<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

/**
 * N100/N69 — Era + Geography + Ethnicity Repository Routing
 *
 * A static matrix that tells the genealogy agent which repositories to
 * prioritize BEFORE searching, based on a person's era, region, and ethnicity.
 *
 * Matrix dimensions:
 *   - Era: Colonial (pre-1776) → Modern (1945+) — 8 buckets
 *   - Region: 7 US regions + UK/Ireland, Scandinavia, France, Eastern Europe,
 *             Italy, Canada, German origin
 *   - Ethnicity: african_american, jewish, default
 *
 * Each returned repository includes a `tool_name` key mapping to the exact
 * agent_tool_registry name where a direct tool exists.
 */
class RepositoryRoutingService
{
    /**
     * Get prioritized repository list for a person based on era, geography, and ethnicity.
     */
    public function getRepositoriesForPerson(int $personId): array
    {
        $person = DB::selectOne(
            "SELECT id, birth_date, birth_place, death_date, death_place,
                    nationality, religion, primary_language
             FROM genealogy_persons WHERE id = ?",
            [$personId]
        );

        if (!$person) {
            return ['error' => 'Person not found', 'priority_repositories' => []];
        }

        $era       = $this->inferEra($person->birth_date, $person->death_date);
        $region    = $this->inferRegion($person->birth_place ?? $person->death_place ?? '');
        $ethnicity = $this->inferEthnicity(
            $person->nationality ?? '',
            $person->religion ?? '',
            $person->primary_language ?? '',
            $era,
            $region
        );

        $repos = $this->lookupMatrix($era, $region, $ethnicity);

        return [
            'person_id'            => $personId,
            'era'                  => $era,
            'region'               => $region,
            'ethnicity'            => $ethnicity,
            'priority_repositories' => $repos,
            'tip'                  => 'Search repositories in priority order. Use tool_name when available. Stop when evidence quality is sufficient.',
        ];
    }

    /**
     * Look up prioritized repository list for a given era + region + ethnicity.
     */
    public function lookupMatrix(string $era, string $region, string $ethnicity = 'default'): array
    {
        $always = [
            ['name' => 'FamilySearch',   'url' => 'https://www.familysearch.org/search',  'record_types' => ['vital', 'census', 'church', 'military', 'immigration'], 'priority' => 1, 'tool_name' => null, 'notes' => 'Manual/browser-only source; no supported PLOS API integration'],
            ['name' => 'Ancestry.com',   'url' => 'https://www.ancestry.com/search',       'record_types' => ['census', 'vital', 'military', 'immigration', 'newspaper'], 'priority' => 2, 'tool_name' => null, 'notes' => 'Largest paid database; check library free access'],
            ['name' => 'WikiTree',       'url' => 'https://www.wikitree.com',              'record_types' => ['family_tree'], 'priority' => 7, 'tool_name' => 'wikitree_search', 'notes' => 'Collaborative tree; cross-reference with sources'],
            ['name' => 'FindAGrave',     'url' => 'https://www.findagrave.com',            'record_types' => ['cemetery', 'death'], 'priority' => 8, 'tool_name' => null, 'notes' => 'Cemetery records, death dates, photos'],
        ];

        $eraBased       = $this->eraSpecificRepos($era);
        $regionBased    = $this->regionSpecificRepos($region, $era);
        $ethnicityBased = $this->ethnicitySpecificRepos($ethnicity, $era, $region);

        $all = array_merge($always, $eraBased, $regionBased, $ethnicityBased);

        usort($all, fn($a, $b) => $a['priority'] <=> $b['priority']);

        // Deduplicate by name, first occurrence wins (lowest priority = best)
        $seen   = [];
        $unique = [];
        foreach ($all as $repo) {
            if (!isset($seen[$repo['name']])) {
                $seen[$repo['name']] = true;
                $unique[] = $repo;
            }
        }

        return $unique;
    }

    // -------------------------------------------------------------------------
    // Era-specific repositories
    // -------------------------------------------------------------------------

    private function eraSpecificRepos(string $era): array
    {
        return match ($era) {
            'colonial' => [
                ['name' => 'New England Historic Genealogical Society', 'url' => 'https://www.americanancestors.org',           'record_types' => ['vital', 'church', 'land'],     'priority' => 3, 'tool_name' => null,               'notes' => 'Manual membership lookup; best for pre-1776 New England vital records, church records, probate'],
                ['name' => 'Internet Archive (Colonial Records)',        'url' => 'https://archive.org',                        'record_types' => ['church', 'land', 'probate'],   'priority' => 4, 'tool_name' => null,              'notes' => 'Digitized colonial church registers, land records'],
                ['name' => 'Chronicling America (Newspapers.gov)',       'url' => 'https://chroniclingamerica.loc.gov',         'record_types' => ['newspaper'],                  'priority' => 7, 'tool_name' => 'newspaper_search', 'notes' => 'Colonial newspapers; limited but available for some regions'],
            ],
            'revolutionary' => [
                ['name' => 'DAR Genealogical Records',     'url' => 'https://www.dar.org/library/genealogy',                                        'record_types' => ['military', 'vital'],   'priority' => 3, 'tool_name' => 'dar_search',  'notes' => 'Revolutionary War service records and lineage papers'],
                ['name' => 'NARA Revolutionary War Records', 'url' => 'https://www.archives.gov/research/military/american-revolution',             'record_types' => ['military'],           'priority' => 4, 'tool_name' => 'nara_search', 'notes' => 'Pension files, service records (M804, M805, M246)'],
                ['name' => 'Fold3',                        'url' => 'https://www.fold3.com',                                                         'record_types' => ['military'],           'priority' => 5, 'tool_name' => null,           'notes' => 'Manual subscription lookup; extensive Revolutionary War coverage'],
            ],
            'antebellum' => [
                ['name' => 'NARA Federal Census 1790-1860',     'url' => 'https://www.archives.gov/research/census',  'record_types' => ['census'],            'priority' => 3, 'tool_name' => 'nara_search',      'notes' => '1790-1860 federal censuses; no name index before 1850'],
                ['name' => 'Chronicling America (Newspapers.gov)', 'url' => 'https://chroniclingamerica.loc.gov',     'record_types' => ['newspaper'],         'priority' => 4, 'tool_name' => 'newspaper_search', 'notes' => 'Free; 1770-1963; strong 1840-1860 coverage'],
                ['name' => 'Internet Archive (State Records)',   'url' => 'https://archive.org',                      'record_types' => ['vital', 'church'],   'priority' => 5, 'tool_name' => null,              'notes' => 'Digitized county histories, church records'],
            ],
            'civil_war' => [
                ['name' => 'NARA Civil War Records',                  'url' => 'https://www.archives.gov/research/military/civil-war',               'record_types' => ['military'],           'priority' => 3, 'tool_name' => 'nara_search',  'notes' => 'Service records, pension files (37M+ records), compiled military service'],
                ['name' => 'Fold3',                                   'url' => 'https://www.fold3.com',                                              'record_types' => ['military', 'pension'], 'priority' => 4, 'tool_name' => null,           'notes' => 'Manual subscription lookup for Civil War pension files and service records'],
                ['name' => 'Civil War Soldiers and Sailors System',   'url' => 'https://www.nps.gov/civilwar/soldiers-and-sailors-database.htm',     'record_types' => ['military'],           'priority' => 5, 'tool_name' => null,           'notes' => 'Free NPS database; quick name lookup'],
                ['name' => 'NARA 1860-1870 Census',                   'url' => 'https://www.archives.gov/research/census',                          'record_types' => ['census'],             'priority' => 6, 'tool_name' => 'nara_search',  'notes' => 'Census captures household before/after war service'],
            ],
            'gilded_age' => [
                ['name' => 'NARA 1870-1900 Census',         'url' => 'https://www.archives.gov/research/census',                            'record_types' => ['census'],                        'priority' => 3, 'tool_name' => 'nara_search',       'notes' => 'State-specific schedules; 1880 census is best indexed'],
                ['name' => 'Naturalization Records (NARA)', 'url' => 'https://www.archives.gov/research/immigration/naturalization',         'record_types' => ['immigration', 'naturalization'], 'priority' => 4, 'tool_name' => 'nara_search',       'notes' => 'Heavy immigration 1870-1900; court naturalization papers'],
                ['name' => 'Castle Garden / Ellis Island',  'url' => 'https://www.statueofliberty.org/ellis-island',                        'record_types' => ['immigration'],                   'priority' => 5, 'tool_name' => 'ellis_island_search', 'notes' => 'Pre-Ellis Island arrivals (1820-1892) at Castle Garden; Ellis from 1892'],
                ['name' => 'Chronicling America',           'url' => 'https://chroniclingamerica.loc.gov',                                  'record_types' => ['newspaper'],                     'priority' => 6, 'tool_name' => 'newspaper_search',   'notes' => 'Excellent coverage 1880-1900'],
            ],
            'progressive' => [
                ['name' => 'NARA 1900-1920 Census',   'url' => 'https://www.archives.gov/research/census',                        'record_types' => ['census'],        'priority' => 3, 'tool_name' => 'nara_search',       'notes' => '1900, 1910, 1920 censuses fully indexed'],
                ['name' => 'WWI Draft Registration Cards', 'url' => 'https://www.ancestry.com/search/collections/6482',           'record_types' => ['military'],      'priority' => 4, 'tool_name' => null,               'notes' => '24M men registered 1917-1918; excellent for ages 18-45 in 1918'],
                ['name' => 'Ellis Island Records',     'url' => 'https://www.libertyellisfoundation.org',                         'record_types' => ['immigration'],   'priority' => 5, 'tool_name' => 'ellis_island_search', 'notes' => 'Peak immigration 1900-1920; free search'],
                ['name' => 'Chronicling America',      'url' => 'https://chroniclingamerica.loc.gov',                             'record_types' => ['newspaper'],     'priority' => 6, 'tool_name' => 'newspaper_search',  'notes' => 'Free; strong coverage through 1920'],
            ],
            'interwar' => [
                ['name' => 'NARA 1930 Census',      'url' => 'https://www.archives.gov/research/census',                    'record_types' => ['census'],    'priority' => 3, 'tool_name' => 'nara_search', 'notes' => 'Last fully released census (1940 released 2012)'],
                ['name' => 'NARA 1940 Census',      'url' => 'https://1940census.archives.gov',                            'record_types' => ['census'],    'priority' => 4, 'tool_name' => 'nara_search', 'notes' => '1940 census fully indexed; shows pre-WWII addresses'],
                ['name' => 'WWII Draft Cards',      'url' => 'https://www.ancestry.com/search/collections/2238',          'record_types' => ['military'],  'priority' => 5, 'tool_name' => null,          'notes' => "Old Man's Draft (1942): men 45-64; younger men in SSNR"],
                ['name' => 'Social Security Death Index', 'url' => 'https://www.ssa.gov/foia/request.html',                'record_types' => ['death'],     'priority' => 6, 'tool_name' => null,          'notes' => 'SS-5 application reveals birth date, parents, address'],
            ],
            'modern' => [
                ['name' => 'Social Security Death Index',       'url' => 'https://www.ssa.gov/foia/request.html',  'record_types' => ['death'],           'priority' => 3, 'tool_name' => null, 'notes' => 'SS-5 application (via FOIA) reveals birth info and parents'],
                ['name' => 'State Vital Records (direct)',      'url' => 'https://www.cdc.gov/nchs/w2w.htm',       'record_types' => ['vital'],           'priority' => 4, 'tool_name' => null, 'notes' => 'State vital records offices; most have online ordering now'],
                ['name' => 'Obituary/Funeral Home Databases',  'url' => 'https://www.legacy.com',                 'record_types' => ['death', 'obituary'], 'priority' => 5, 'tool_name' => 'newspaper_search_obituaries', 'notes' => 'Legacy.com and funeral home websites for post-1950 deaths'],
            ],
            default => [],
        };
    }

    // -------------------------------------------------------------------------
    // Region-specific repositories
    // -------------------------------------------------------------------------

    private function regionSpecificRepos(string $region, string $era): array
    {
        return match ($region) {
            'new_england' => [
                ['name' => 'American Ancestors (NEHGS)',  'url' => 'https://www.americanancestors.org',                                                       'record_types' => ['vital', 'church', 'probate'], 'priority' => 3, 'tool_name' => null, 'notes' => 'Manual membership lookup; best resource for New England vital records from the 1600s'],
                ['name' => 'NEHGS LifeVitals',            'url' => 'https://www.americanancestors.org/databases/massachusetts-vital-records',                  'record_types' => ['vital'],                      'priority' => 4, 'tool_name' => null, 'notes' => 'Manual membership lookup for MA, ME, NH, VT, RI vital records'],
            ],
            'mid_atlantic' => [
                ['name' => 'Family History Center (Philadelphia area)', 'url' => 'https://www.familysearch.org/en/wiki/Pennsylvania_Genealogy', 'record_types' => ['church', 'vital', 'land'], 'priority' => 4, 'tool_name' => null, 'notes' => 'Manual/browser-only PA German church records (Reformed, Lutheran, Mennonite, Brethren)'],
                ['name' => 'New York State Archives',                    'url' => 'https://www.archives.nysed.gov',                             'record_types' => ['vital', 'land', 'census'], 'priority' => 5, 'tool_name' => null,                      'notes' => 'NY state censuses (1855, 1865, 1875, 1892, 1905, 1915, 1925)'],
            ],
            'south' => [
                ['name' => "Freedmen's Bureau Records", 'url' => 'https://www.freedmensbureau.com',                              'record_types' => ['vital', 'labor'],       'priority' => 4, 'tool_name' => 'freedmens_bureau_search', 'notes' => 'For post-1865 research on African-American families in the South'],
                ['name' => 'Southern States Archives',  'url' => 'https://www.familysearch.org/en/wiki/Southern_States_Genealogy', 'record_types' => ['vital', 'church', 'land'], 'priority' => 5, 'tool_name' => null, 'notes' => 'Manual/browser-only county-level records; FamilySearch has extensive indexing for the South'],
            ],
            'midwest' => [
                ['name' => 'Allen County Public Library (Fort Wayne)', 'url' => 'https://www.genealogycenter.org', 'record_types' => ['various'], 'priority' => 4, 'tool_name' => null,              'notes' => 'Second largest genealogy collection in USA; excellent Midwest coverage'],
                ['name' => 'Midwest State Land Records',               'url' => 'https://glorecords.blm.gov',     'record_types' => ['land'],    'priority' => 5, 'tool_name' => null,              'notes' => 'BLM federal land records (GLO) — land entries identify first settlers'],
            ],
            'great_plains' => [
                ['name' => 'Midwest State Land Records',       'url' => 'https://glorecords.blm.gov',                                     'record_types' => ['land'],    'priority' => 4, 'tool_name' => null, 'notes' => 'Homestead Act filings; key for 1870-1910 Great Plains settlement'],
                ['name' => 'State Historical Society Records', 'url' => 'https://www.familysearch.org/en/wiki/Great_Plains_Genealogy',    'record_types' => ['vital', 'church'], 'priority' => 5, 'tool_name' => null, 'notes' => 'Manual/browser-only ND, SD, NE, KS state historical societies; strong for immigrant communities'],
            ],
            'southwest' => [
                ['name' => 'Catholic Church Records (Southwest)', 'url' => 'https://www.familysearch.org/en/wiki/Texas_Genealogy',        'record_types' => ['church', 'vital'], 'priority' => 4, 'tool_name' => null, 'notes' => 'Manual/browser-only Spanish/Mexican colonial church records; TX, NM, AZ, OK'],
                ['name' => 'NARA Fort Worth (Southwest Records)', 'url' => 'https://www.archives.gov/fort-worth',                         'record_types' => ['land', 'military', 'vital'], 'priority' => 5, 'tool_name' => 'nara_search', 'notes' => 'Regional NARA for TX, OK, NM, LA, AR, AZ, OK — Native American records'],
            ],
            'west' => [
                ['name' => 'California State Archives',           'url' => 'https://www.sos.ca.gov/archives',                    'record_types' => ['vital', 'land'],     'priority' => 4, 'tool_name' => null,                      'notes' => 'CA death records, land grants, pre-1905 births not in state system'],
                ['name' => 'NARA Seattle / Riverside (West)',     'url' => 'https://www.archives.gov/pacific',                   'record_types' => ['immigration', 'land', 'military'], 'priority' => 5, 'tool_name' => 'nara_search', 'notes' => 'Pacific coast immigration records, Chinese exclusion files'],
            ],
            'german_origin' => [
                ['name' => 'Archion (German Church Records)',  'url' => 'https://www.archion.de',            'record_types' => ['church'],       'priority' => 3, 'tool_name' => 'german_church_records_search', 'notes' => 'German Protestant church records digitized; 1580-1950'],
                ['name' => 'Matricula Online (Catholic)',      'url' => 'https://www.matricula-online.eu',   'record_types' => ['church'],       'priority' => 4, 'tool_name' => 'german_church_records_search', 'notes' => 'Catholic parish registers for Germany, Austria, Poland'],
                ['name' => 'Gedbas / Ahnenblatt',             'url' => 'https://gedbas.genealogy.net',      'record_types' => ['family_tree'],  'priority' => 5, 'tool_name' => null,                          'notes' => 'German genealogy database; GEDCOM submissions from researchers'],
            ],
            'uk_ireland' => [
                ['name' => 'FreeBMD (UK Vital Records)',       'url' => 'https://www.freebmd.org.uk',        'record_types' => ['vital'],        'priority' => 3, 'tool_name' => null,                'notes' => 'Free transcription of England & Wales BMD index 1837-1984'],
                ['name' => 'ScotlandsPeople',                  'url' => 'https://www.scotlandspeople.gov.uk','record_types' => ['vital', 'church', 'census'], 'priority' => 4, 'tool_name' => null, 'notes' => 'Official Scottish records; OPRs, censuses, statutory registers'],
                ['name' => "Griffith's Valuation (Ireland)",   'url' => 'https://www.askaboutireland.ie',    'record_types' => ['land'],         'priority' => 5, 'tool_name' => null,                'notes' => '1847-1864 Irish property survey; substitute for lost census records'],
                ['name' => 'National Archives Kew',            'url' => 'https://www.nationalarchives.gov.uk', 'record_types' => ['military', 'probate', 'land'], 'priority' => 6, 'tool_name' => null, 'notes' => 'English national records; wills, military service, court records'],
            ],
            'scandinavia' => [
                ['name' => 'Digitalarkivet (Norwegian)',        'url' => 'https://www.digitalarkivet.no',     'record_types' => ['church', 'census'], 'priority' => 3, 'tool_name' => null, 'notes' => 'Norwegian National Archives — church books, census, emigration records; free'],
                ['name' => 'Swedish Church Records (FamilySearch)', 'url' => 'https://www.familysearch.org/en/wiki/Sweden_Genealogy', 'record_types' => ['church'], 'priority' => 4, 'tool_name' => null, 'notes' => 'Manual/browser-only household exam records and birth/death registers'],
                ['name' => 'Danish National Archives',          'url' => 'https://www.sa.dk',                 'record_types' => ['church', 'census'], 'priority' => 5, 'tool_name' => null, 'notes' => 'Danish church records, emigration lists, censuses; free access'],
            ],
            'france' => [
                ['name' => 'FranceArchives',                   'url' => 'https://francearchives.gouv.fr',    'record_types' => ['vital', 'church', 'military'], 'priority' => 3, 'tool_name' => null, 'notes' => 'Portal to French departmental archives; civil registration from 1792'],
                ['name' => 'Matricula Online (Alsace-Lorraine)', 'url' => 'https://www.matricula-online.eu', 'record_types' => ['church'],                      'priority' => 4, 'tool_name' => 'german_church_records_search', 'notes' => 'Catholic registers for Alsace-Lorraine (historically German); bilingual records'],
            ],
            'eastern_europe' => [
                ['name' => 'Geneteka (Poland)',                 'url' => 'https://geneteka.genealodzy.pl',    'record_types' => ['vital', 'church'], 'priority' => 3, 'tool_name' => null,                          'notes' => 'Free indexed Polish vital records; 12M+ entries'],
                ['name' => 'Matricula Online (Eastern Europe)', 'url' => 'https://www.matricula-online.eu',  'record_types' => ['church'],          'priority' => 4, 'tool_name' => 'german_church_records_search', 'notes' => 'Catholic parish registers for Poland, Czech Rep., Slovakia, Austria, Hungary'],
                ['name' => 'Archion (German-speaking areas)',   'url' => 'https://www.archion.de',           'record_types' => ['church'],          'priority' => 5, 'tool_name' => 'german_church_records_search', 'notes' => 'Protestant church records covering former German territories in Eastern Europe'],
            ],
            'italy' => [
                ['name' => 'Antenati (Italian Vital Records)',  'url' => 'https://antenati.cultura.gov.it',  'record_types' => ['vital'],           'priority' => 3, 'tool_name' => null, 'notes' => 'Free access to Italian civil registration 1809-1910; state archives'],
                ['name' => 'Matricula Online (Italy)',          'url' => 'https://www.matricula-online.eu',  'record_types' => ['church'],          'priority' => 4, 'tool_name' => 'german_church_records_search', 'notes' => 'Catholic parish registers for Northern Italy (Lombardy, Veneto, Trentino)'],
                ['name' => 'FamilySearch Italy Collections',   'url' => 'https://www.familysearch.org/en/wiki/Italy_Genealogy', 'record_types' => ['vital', 'church'], 'priority' => 5, 'tool_name' => null, 'notes' => 'Manual/browser-only civil registration and church records for most Italian provinces'],
            ],
            'canada' => [
                ['name' => 'Library and Archives Canada',       'url' => 'https://www.bac-lac.gc.ca',        'record_types' => ['census', 'immigration', 'military'], 'priority' => 3, 'tool_name' => null, 'notes' => 'Canadian federal records; censuses 1851-1926, passenger lists, WWI/WWII'],
                ['name' => 'Ontario Vital Statistics',          'url' => 'https://www.ontario.ca/page/vital-statistics', 'record_types' => ['vital'],              'priority' => 4, 'tool_name' => null, 'notes' => 'ON birth/death/marriage records; online ordering for deaths 1869-1921'],
                ['name' => 'FamilySearch Canada',               'url' => 'https://www.familysearch.org/en/wiki/Canada_Genealogy', 'record_types' => ['vital', 'church', 'census'], 'priority' => 5, 'tool_name' => null, 'notes' => 'Manual/browser-only province-level collections; Quebec notarial records, church registers'],
            ],
            default => [],
        };
    }

    // -------------------------------------------------------------------------
    // Ethnicity-specific repositories
    // -------------------------------------------------------------------------

    private function ethnicitySpecificRepos(string $ethnicity, string $era, string $region): array
    {
        return match ($ethnicity) {
            'african_american' => [
                ['name' => "Freedmen's Bureau Records",          'url' => 'https://www.freedmensbureau.com',                          'record_types' => ['vital', 'labor', 'marriage'], 'priority' => 2, 'tool_name' => 'freedmens_bureau_search', 'notes' => 'Labor contracts, marriage registers, ration records 1865-1872'],
                ['name' => 'Fold3 USCT Records',                 'url' => 'https://www.fold3.com/title/318/civil-war-usct',           'record_types' => ['military'],                   'priority' => 3, 'tool_name' => null,                        'notes' => 'Manual subscription lookup for United States Colored Troops service records and pension files'],
                ['name' => 'ChroniclingAmerica (Black press)',   'url' => 'https://chroniclingamerica.loc.gov',                      'record_types' => ['newspaper'],                  'priority' => 5, 'tool_name' => 'newspaper_search',          'notes' => 'Search Black newspapers: Chicago Defender, Pittsburgh Courier, etc.'],
                ['name' => 'Fold3 Slave Schedules',              'url' => 'https://www.fold3.com/title/70/1850-us-federal-census-slave-schedules', 'record_types' => ['census'], 'priority' => 6, 'tool_name' => null,                        'notes' => 'Manual subscription lookup for 1850 and 1860 slave schedules'],
            ],
            'jewish' => [
                ['name' => 'JRI-Poland',                         'url' => 'https://www.jri-poland.org',                              'record_types' => ['vital', 'church'],  'priority' => 2, 'tool_name' => null, 'notes' => 'Jewish Records Indexing Poland; 6M+ vital records from Polish Jewish communities'],
                ['name' => 'Yad Vashem Central Database',        'url' => 'https://yvng.yadvashem.org',                              'record_types' => ['death', 'memorial'],'priority' => 3, 'tool_name' => null, 'notes' => 'Holocaust victim names database; also pre-war community records'],
                ['name' => 'JewishGen.org',                      'url' => 'https://www.jewishgen.org',                               'record_types' => ['vital', 'various'], 'priority' => 4, 'tool_name' => null, 'notes' => 'Largest Jewish genealogy portal; town finder, ShtetlSeeker, databases'],
                ['name' => 'Gesher Galicia',                     'url' => 'https://www.geshergalicia.org',                           'record_types' => ['vital', 'church'],  'priority' => 5, 'tool_name' => null, 'notes' => 'Galician Jewish records (modern Ukraine/Poland border region)'],
            ],
            default => [],
        };
    }

    // -------------------------------------------------------------------------
    // Inference methods
    // -------------------------------------------------------------------------

    private function inferEra(?string $birthDate, ?string $deathDate): string
    {
        $year = $this->extractYear($birthDate ?? '') ?? $this->extractYear($deathDate ?? '');
        if (!$year) return 'unknown';

        return match (true) {
            $year < 1776 => 'colonial',
            $year < 1800 => 'revolutionary',
            $year < 1860 => 'antebellum',
            $year < 1875 => 'civil_war',
            $year < 1900 => 'gilded_age',
            $year < 1920 => 'progressive',
            $year < 1945 => 'interwar',
            default      => 'modern',
        };
    }

    private function inferRegion(string $place): string
    {
        $lower = strtolower($place);

        $regionMap = [
            'new_england'    => ['maine', 'new hampshire', 'vermont', 'massachusetts', 'rhode island', 'connecticut', ',me', ',nh', ',vt', ',ma', ',ri', ',ct'],
            'mid_atlantic'   => ['new york', 'new jersey', 'pennsylvania', 'delaware', 'maryland', ',ny', ',nj', ',pa', ',de', ',md'],
            'south'          => ['virginia', 'west virginia', 'north carolina', 'south carolina', 'georgia', 'florida', 'alabama', 'mississippi', 'louisiana', 'arkansas', 'tennessee', 'kentucky', ',va', ',wv', ',nc', ',sc', ',ga', ',fl', ',al', ',ms', ',la', ',ar', ',tn', ',ky'],
            'midwest'        => ['ohio', 'indiana', 'illinois', 'michigan', 'wisconsin', 'minnesota', 'iowa', 'missouri', ',oh', ',in', ',il', ',mi', ',wi', ',mn', ',ia', ',mo'],
            'great_plains'   => ['north dakota', 'south dakota', 'nebraska', 'kansas', ',nd', ',sd', ',ne', ',ks'],
            'southwest'      => ['texas', 'oklahoma', 'new mexico', 'arizona', ',tx', ',ok', ',nm', ',az'],
            'west'           => ['california', 'oregon', 'washington', 'nevada', 'utah', 'colorado', 'idaho', 'montana', 'wyoming', ',ca', ',or', ',wa', ',nv', ',ut', ',co', ',id', ',mt', ',wy'],
            'uk_ireland'     => ['england', 'scotland', 'wales', 'ireland', 'northern ireland', 'britain', 'united kingdom', ',gb', ',uk', ',ie'],
            'scandinavia'    => ['sweden', 'norway', 'denmark', 'finland', ',se', ',no', ',dk', ',fi'],
            'france'         => ['france', 'alsace', 'lorraine', ',fr'],
            'eastern_europe' => ['poland', 'czech', 'bohemia', 'moravia', 'austria', 'hungary', 'slovakia', 'ukraine', 'galicia', ',pl', ',cz', ',at', ',hu', ',sk', ',ua'],
            'italy'          => ['italy', 'sicily', 'calabria', 'sicilia', ',it'],
            'canada'         => ['canada', 'ontario', 'quebec', 'nova scotia', 'british columbia', 'alberta', 'manitoba', 'saskatchewan', ',on', ',qc', ',ns', ',bc', ',ab', ',mb', ',sk'],
        ];

        foreach ($regionMap as $region => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $region;
                }
            }
        }

        // German-origin heuristic (for diaspora: born in US but German ancestry)
        if (preg_match('/germany|deutschland|bayern|bavaria|württ|württemberg|hessen|hesse|preuss|prusse|sachsen|saxony|westfalen|westphalia/i', $place)) {
            return 'german_origin';
        }

        return 'unknown';
    }

    /**
     * Infer ethnicity from GEDCOM fields, with graceful degradation.
     * Returns 'african_american', 'jewish', or 'default'.
     */
    private function inferEthnicity(
        string $nationality,
        string $religion,
        string $language,
        string $era,
        string $region
    ): string {
        $nat  = strtolower($nationality);
        $rel  = strtolower($religion);
        $lang = strtolower($language);

        // Jewish — religion is the strongest signal
        if (preg_match('/jewish|hebrew|israelite|judaism|judaic/i', $rel)) {
            return 'jewish';
        }
        if (preg_match('/jewish/i', $nat)) {
            return 'jewish';
        }

        // African American — religion/nationality signals
        if (preg_match('/african american|black american|afro-american/i', $nat)) {
            return 'african_american';
        }
        if (preg_match('/african methodist|ame church|african baptist/i', $rel)) {
            return 'african_american';
        }

        return 'default';
    }

    private function extractYear(?string $date): ?int
    {
        if (!$date) return null;
        if (preg_match('/(\d{4})/', $date, $m)) {
            $y = (int) $m[1];
            return ($y >= 1500 && $y <= 2100) ? $y : null;
        }
        return null;
    }
}
