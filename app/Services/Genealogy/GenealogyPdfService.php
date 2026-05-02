<?php

namespace App\Services\Genealogy;

use TCPDF;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Phase 6: PDF Reports Service
 *
 * Generates genealogy PDF reports using TCPDF
 */
class GenealogyPdfService
{
    private GenealogyService $genealogyService;

    public function __construct(GenealogyService $genealogyService)
    {
        $this->genealogyService = $genealogyService;
    }

    /**
     * Generate Pedigree Chart PDF
     */
    public function generatePedigreeChart(int $personId, int $generations = 4): string
    {
        $pdf = $this->initPdf('Pedigree Chart');

        // Get person
        $person = $this->getPerson($personId);
        if (!$person) {
            throw new Exception('Person not found');
        }

        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Pedigree Chart for ' . $this->formatName($person), 0, 1, 'C');
        $pdf->Ln(5);

        // Get ancestors using the Ahnentafel builder (no public getAncestors method)
        $ancestors = $this->buildAhnentafelList($personId, $generations);

        // Build pedigree structure
        $pdf->SetFont('helvetica', '', 10);
        $this->renderPedigreeTable($pdf, $person, $ancestors, $generations);

        return $pdf->Output('pedigree_' . $personId . '.pdf', 'S');
    }

    /**
     * Generate Family Group Sheet PDF
     */
    public function generateFamilyGroupSheet(int $familyId): string
    {
        $pdf = $this->initPdf('Family Group Sheet');

        // Get family data
        $family = DB::selectOne("
            SELECT f.*,
                   h.given_name as husband_given, h.surname as husband_surname,
                   h.birth_date as husband_birth, h.birth_place as husband_birth_place,
                   h.death_date as husband_death, h.death_place as husband_death_place,
                   w.given_name as wife_given, w.surname as wife_surname,
                   w.birth_date as wife_birth, w.birth_place as wife_birth_place,
                   w.death_date as wife_death, w.death_place as wife_death_place
            FROM genealogy_families f
            LEFT JOIN genealogy_persons h ON f.husband_id = h.id
            LEFT JOIN genealogy_persons w ON f.wife_id = w.id
            WHERE f.id = ?
        ", [$familyId]);

        if (!$family) {
            throw new Exception('Family not found');
        }

        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Family Group Sheet', 0, 1, 'C');
        $pdf->Ln(5);

        // Husband section
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(0, 8, 'Husband', 1, 1, 'L', true);

        $pdf->SetFont('helvetica', '', 10);
        $this->addPersonRow($pdf, 'Name', trim(($family->husband_given ?? '') . ' ' . ($family->husband_surname ?? '')));
        $this->addPersonRow($pdf, 'Birth', $this->formatDatePlace($family->husband_birth, $family->husband_birth_place));
        $this->addPersonRow($pdf, 'Death', $this->formatDatePlace($family->husband_death, $family->husband_death_place));
        $pdf->Ln(3);

        // Wife section
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Wife', 1, 1, 'L', true);

        $pdf->SetFont('helvetica', '', 10);
        $this->addPersonRow($pdf, 'Name', trim(($family->wife_given ?? '') . ' ' . ($family->wife_surname ?? '')));
        $this->addPersonRow($pdf, 'Birth', $this->formatDatePlace($family->wife_birth, $family->wife_birth_place));
        $this->addPersonRow($pdf, 'Death', $this->formatDatePlace($family->wife_death, $family->wife_death_place));
        $pdf->Ln(3);

        // Marriage info
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Marriage', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 10);
        $this->addPersonRow($pdf, 'Date', $family->marriage_date ?? 'Unknown');
        $this->addPersonRow($pdf, 'Place', $family->marriage_place ?? 'Unknown');
        $pdf->Ln(5);

        // Children section
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Children', 1, 1, 'L', true);

        $children = DB::select("
            SELECT p.given_name, p.surname, p.birth_date, p.birth_place, p.death_date, p.sex
            FROM genealogy_children fc
            JOIN genealogy_persons p ON fc.person_id = p.id
            WHERE fc.family_id = ?
            ORDER BY p.birth_date, p.id
        ", [$familyId]);

        $pdf->SetFont('helvetica', '', 9);
        if (empty($children)) {
            $pdf->Cell(0, 6, 'No children recorded', 0, 1);
        } else {
            $i = 1;
            foreach ($children as $child) {
                $gender = $child->sex === 'M' ? 'M' : ($child->sex === 'F' ? 'F' : '?');
                $name = trim(($child->given_name ?? '') . ' ' . ($child->surname ?? ''));
                $birth = $child->birth_date ? "b. {$child->birth_date}" : '';
                $death = $child->death_date ? "d. {$child->death_date}" : '';

                $pdf->Cell(10, 5, $i . '.', 0, 0);
                $pdf->Cell(10, 5, $gender, 0, 0);
                $pdf->Cell(60, 5, $name, 0, 0);
                $pdf->Cell(35, 5, $birth, 0, 0);
                $pdf->Cell(35, 5, $death, 0, 1);
                $i++;
            }
        }

        return $pdf->Output('family_group_' . $familyId . '.pdf', 'S');
    }

    /**
     * Generate Descendant Report PDF
     */
    public function generateDescendantReport(int $personId, int $generations = 4): string
    {
        $pdf = $this->initPdf('Descendant Report');

        $person = $this->getPerson($personId);
        if (!$person) {
            throw new Exception('Person not found');
        }

        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Descendants of ' . $this->formatName($person), 0, 1, 'C');
        $pdf->Ln(5);

        // Get descendants via public getDescendantReport method
        $report = $this->genealogyService->getDescendantReport($personId, $generations);
        $descendants = $report['descendants'] ?? [];

        $pdf->SetFont('helvetica', '', 10);
        $this->renderDescendantList($pdf, $descendants, 0);

        return $pdf->Output('descendants_' . $personId . '.pdf', 'S');
    }

    /**
     * Generate Ahnentafel Report PDF
     */
    public function generateAhnentafelReport(int $personId, int $generations = 8): string
    {
        $pdf = $this->initPdf('Ahnentafel Report');

        $person = $this->getPerson($personId);
        if (!$person) {
            throw new Exception('Person not found');
        }

        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Ahnentafel Report for ' . $this->formatName($person), 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Ancestor list in numerical order', 0, 1, 'C');
        $pdf->Ln(5);

        // Build ahnentafel list
        $ancestors = $this->buildAhnentafelList($personId, $generations);

        $pdf->SetFont('helvetica', '', 10);
        foreach ($ancestors as $num => $ancestor) {
            $generation = floor(log($num, 2)) + 1;
            $indent = ($generation - 1) * 5;

            $line = str_pad($num, 4, ' ', STR_PAD_LEFT) . '. ';
            $line .= $this->formatName($ancestor);

            if ($ancestor->birth_date) {
                $line .= ' (b. ' . $ancestor->birth_date;
                if ($ancestor->death_date) {
                    $line .= ' - d. ' . $ancestor->death_date;
                }
                $line .= ')';
            }

            $pdf->SetX(10 + $indent);
            $pdf->MultiCell(0, 5, $line, 0, 'L');
        }

        return $pdf->Output('ahnentafel_' . $personId . '.pdf', 'S');
    }

    /**
     * Generate Individual Summary PDF
     */
    public function generateIndividualSummary(int $personId): string
    {
        $pdf = $this->initPdf('Individual Summary');

        $person = $this->getPerson($personId);
        if (!$person) {
            throw new Exception('Person not found');
        }

        // Title
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, $this->formatName($person), 0, 1, 'C');
        $pdf->Ln(5);

        // Vital Information
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(0, 8, 'Vital Information', 1, 1, 'L', true);

        $pdf->SetFont('helvetica', '', 10);
        $this->addPersonRow($pdf, 'Gender', $person->gender === 'M' ? 'Male' : ($person->gender === 'F' ? 'Female' : 'Unknown'));
        $this->addPersonRow($pdf, 'Birth', $this->formatDatePlace($person->birth_date, $person->birth_place));
        $this->addPersonRow($pdf, 'Death', $this->formatDatePlace($person->death_date, $person->death_place));
        $pdf->Ln(3);

        // Parents
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Parents', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 10);

        $parents = $this->getParents($personId);
        if ($parents['father']) {
            $this->addPersonRow($pdf, 'Father', $this->formatName($parents['father']));
        }
        if ($parents['mother']) {
            $this->addPersonRow($pdf, 'Mother', $this->formatName($parents['mother']));
        }
        if (!$parents['father'] && !$parents['mother']) {
            $pdf->Cell(0, 6, 'No parents recorded', 0, 1);
        }
        $pdf->Ln(3);

        // Spouses
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Marriages', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 10);

        $spouses = $this->getSpouses($personId);
        if (empty($spouses)) {
            $pdf->Cell(0, 6, 'No marriages recorded', 0, 1);
        } else {
            foreach ($spouses as $spouse) {
                $line = $this->formatName($spouse);
                if ($spouse->marriage_date) {
                    $line .= ' (m. ' . $spouse->marriage_date . ')';
                }
                $pdf->Cell(0, 6, $line, 0, 1);
            }
        }
        $pdf->Ln(3);

        // Events
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Events', 1, 1, 'L', true);
        $pdf->SetFont('helvetica', '', 10);

        $events = DB::select("
            SELECT event_type, event_date, event_place, description
            FROM genealogy_events
            WHERE person_id = ?
            ORDER BY event_date
        ", [$personId]);

        if (empty($events)) {
            $pdf->Cell(0, 6, 'No events recorded', 0, 1);
        } else {
            foreach ($events as $event) {
                $line = ucfirst(str_replace('_', ' ', $event->event_type));
                if ($event->event_date) {
                    $line .= ': ' . $event->event_date;
                }
                if ($event->event_place) {
                    $line .= ' at ' . $event->event_place;
                }
                $pdf->Cell(0, 6, $line, 0, 1);
            }
        }

        return $pdf->Output('individual_' . $personId . '.pdf', 'S');
    }

    // ===== Helper Methods =====

    private function initPdf(string $title): TCPDF
    {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        $pdf->SetCreator('PLOS Genealogy');
        $pdf->SetAuthor('PLOS Automation');
        $pdf->SetTitle($title);
        $pdf->SetSubject('Genealogy Report');

        $pdf->setHeaderData('', 0, $title, 'Generated: ' . date('Y-m-d H:i'));

        $pdf->setHeaderFont(['helvetica', '', 10]);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $pdf->SetMargins(15, 25, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(true, 25);

        $pdf->AddPage();

        return $pdf;
    }

    private function getPerson(int $personId): ?object
    {
        return DB::selectOne("
            SELECT *, sex as gender FROM genealogy_persons WHERE id = ?
        ", [$personId]);
    }

    private function getParents(int $personId): array
    {
        $result = ['father' => null, 'mother' => null];

        $parent = DB::selectOne("
            SELECT f.husband_id, f.wife_id,
                   h.given_name as father_given, h.surname as father_surname,
                   w.given_name as mother_given, w.surname as mother_surname
            FROM genealogy_children gc
            JOIN genealogy_families f ON gc.family_id = f.id
            LEFT JOIN genealogy_persons h ON f.husband_id = h.id
            LEFT JOIN genealogy_persons w ON f.wife_id = w.id
            WHERE gc.person_id = ?
            LIMIT 1
        ", [$personId]);

        if ($parent) {
            if ($parent->husband_id) {
                $result['father'] = (object)[
                    'given_name' => $parent->father_given,
                    'surname' => $parent->father_surname
                ];
            }
            if ($parent->wife_id) {
                $result['mother'] = (object)[
                    'given_name' => $parent->mother_given,
                    'surname' => $parent->mother_surname
                ];
            }
        }

        return $result;
    }

    private function getSpouses(int $personId): array
    {
        return DB::select("
            SELECT
                CASE
                    WHEN f.husband_id = ? THEN w.given_name
                    ELSE h.given_name
                END as given_name,
                CASE
                    WHEN f.husband_id = ? THEN w.surname
                    ELSE h.surname
                END as surname,
                f.marriage_date
            FROM genealogy_families f
            LEFT JOIN genealogy_persons h ON f.husband_id = h.id
            LEFT JOIN genealogy_persons w ON f.wife_id = w.id
            WHERE f.husband_id = ? OR f.wife_id = ?
        ", [$personId, $personId, $personId, $personId]);
    }

    private function formatName(object $person): string
    {
        return trim(($person->given_name ?? '') . ' ' . ($person->surname ?? '')) ?: 'Unknown';
    }

    private function formatDatePlace(?string $date, ?string $place): string
    {
        $parts = [];
        if ($date) {
            $parts[] = $date;
        }
        if ($place) {
            $parts[] = $place;
        }
        return implode(' at ', $parts) ?: 'Unknown';
    }

    private function addPersonRow(TCPDF $pdf, string $label, string $value): void
    {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(30, 6, $label . ':', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $value, 0, 1);
    }

    private function renderPedigreeTable(TCPDF $pdf, object $person, array $ancestors, int $generations): void
    {
        // Simplified pedigree table
        $pdf->SetFont('helvetica', '', 9);

        $lineHeight = 8;
        $startY = $pdf->GetY();

        // Generation headers
        for ($g = 1; $g <= $generations; $g++) {
            $startNum = pow(2, $g - 1);
            $endNum = pow(2, $g) - 1;

            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'Generation ' . $g, 0, 1);
            $pdf->SetFont('helvetica', '', 9);

            for ($i = $startNum; $i <= $endNum; $i++) {
                $ancestor = $ancestors[$i] ?? null;
                if ($ancestor) {
                    $name = $this->formatName($ancestor);
                    $dates = '';
                    if ($ancestor->birth_date) {
                        $dates = 'b. ' . $ancestor->birth_date;
                    }
                    if ($ancestor->death_date) {
                        $dates .= ($dates ? ' - ' : '') . 'd. ' . $ancestor->death_date;
                    }
                    $pdf->Cell(10, $lineHeight, $i . '.', 0, 0);
                    $pdf->Cell(80, $lineHeight, $name, 0, 0);
                    $pdf->Cell(0, $lineHeight, $dates, 0, 1);
                } else {
                    $pdf->Cell(10, $lineHeight, $i . '.', 0, 0);
                    $pdf->Cell(0, $lineHeight, '(Unknown)', 0, 1);
                }
            }
            $pdf->Ln(3);
        }
    }

    private function renderDescendantList(TCPDF $pdf, array $descendants, int $level): void
    {
        foreach ($descendants as $desc) {
            $indent = $level * 10;
            $pdf->SetX(15 + $indent);

            $line = $this->formatName($desc);
            if (isset($desc->birth_date)) {
                $line .= ' (b. ' . $desc->birth_date;
                if (isset($desc->death_date)) {
                    $line .= ' - d. ' . $desc->death_date;
                }
                $line .= ')';
            }

            $pdf->MultiCell(0, 5, $line, 0, 'L');

            if (!empty($desc->children)) {
                $this->renderDescendantList($pdf, $desc->children, $level + 1);
            }
        }
    }

    private function buildAhnentafelList(int $personId, int $generations): array
    {
        $list = [];

        // Add the starting person as #1
        $person = $this->getPerson($personId);
        if ($person) {
            $list[1] = $person;
        }

        // Recursively add ancestors
        $maxNum = pow(2, $generations) - 1;
        $this->addAhnentafelAncestors($list, $personId, 1, $maxNum);

        ksort($list);
        return $list;
    }

    private function addAhnentafelAncestors(array &$list, int $personId, int $num, int $maxNum): void
    {
        $fatherNum = $num * 2;
        $motherNum = $num * 2 + 1;

        if ($fatherNum > $maxNum) {
            return;
        }

        // Get parents
        $parentFamily = DB::selectOne("
            SELECT f.husband_id, f.wife_id
            FROM genealogy_children fc
            JOIN genealogy_families f ON fc.family_id = f.id
            WHERE fc.person_id = ?
            LIMIT 1
        ", [$personId]);

        if ($parentFamily) {
            if ($parentFamily->husband_id) {
                $father = $this->getPerson($parentFamily->husband_id);
                if ($father) {
                    $list[$fatherNum] = $father;
                    $this->addAhnentafelAncestors($list, $parentFamily->husband_id, $fatherNum, $maxNum);
                }
            }

            if ($parentFamily->wife_id) {
                $mother = $this->getPerson($parentFamily->wife_id);
                if ($mother) {
                    $list[$motherNum] = $mother;
                    $this->addAhnentafelAncestors($list, $parentFamily->wife_id, $motherNum, $maxNum);
                }
            }
        }
    }
}
