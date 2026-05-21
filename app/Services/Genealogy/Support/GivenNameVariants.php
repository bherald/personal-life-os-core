<?php

namespace App\Services\Genealogy\Support;

/**
 * Curated English-language given-name nickname table used by the 2.1b
 * proximity gate so `Mike Smith` in a body matches a target of
 * `Michael Smith` without relaxing the gate globally. Scope is English
 * because that's what PLOS's current genealogy corpus is in.
 *
 * Entries are bidirectional — passing any alias returns the set
 * including the formal name and all known aliases for that name.
 * Lookups are lowercase.
 */
class GivenNameVariants
{
    /** @var array<string, list<string>> formal → list of known nicknames */
    private const TABLE = [
        'michael' => ['mike', 'mick', 'mickey', 'mikey'],
        'robert' => ['rob', 'bob', 'bobby', 'robbie', 'bert'],
        'william' => ['will', 'bill', 'billy', 'willy', 'willie', 'liam'],
        'richard' => ['rick', 'dick', 'rich', 'ricky'],
        'james' => ['jim', 'jimmy', 'jamie', 'jimbo'],
        'john' => ['jack', 'johnny', 'jonny', 'jon'],
        'thomas' => ['tom', 'tommy'],
        'charles' => ['charlie', 'chuck', 'chas'],
        'edward' => ['ed', 'eddie', 'eddy', 'ned', 'ted', 'teddy'],
        'ernest' => ['ernie'],
        'eugene' => ['gene'],
        'henry' => ['hank', 'harry', 'hal'],
        'david' => ['dave', 'davey', 'davy'],
        'daniel' => ['dan', 'danny'],
        'joseph' => ['joe', 'joey'],
        'anthony' => ['tony'],
        'benjamin' => ['ben', 'benny', 'benji'],
        'alexander' => ['alex', 'al', 'xander', 'sandy', 'alec'],
        'nicholas' => ['nick', 'nicky'],
        'christopher' => ['chris', 'kit'],
        'matthew' => ['matt', 'matty'],
        'patrick' => ['pat', 'patty'],
        'frederick' => ['fred', 'freddie', 'freddy'],
        'samuel' => ['sam', 'sammy'],
        'timothy' => ['tim', 'timmy'],
        'peter' => ['pete', 'petey'],
        'albert' => ['al', 'bert', 'albie'],
        'lawrence' => ['larry', 'lawrie'],
        'kenneth' => ['ken', 'kenny'],
        'ronald' => ['ron', 'ronnie'],
        'donald' => ['don', 'donny', 'donnie'],
        'andrew' => ['andy', 'drew'],
        'francis' => ['frank', 'frankie'],
        'gregory' => ['greg', 'gregg'],
        'philip' => ['phil'],
        'phillip' => ['phil'],
        'george' => ['georgie'],
        'elizabeth' => ['liz', 'beth', 'betty', 'betsy', 'liza', 'eliza', 'lisa', 'libby', 'lizzy'],
        'margaret' => ['maggie', 'meg', 'peggy', 'madge', 'greta', 'marge'],
        'catherine' => ['cathy', 'kate', 'katie', 'cate', 'kat', 'cat', 'kitty'],
        'katherine' => ['kathy', 'kate', 'katie', 'kat', 'cathy', 'cat', 'kitty'],
        'kathryn' => ['kathy', 'kate', 'katie', 'kat', 'cathy', 'cat'],
        'barbara' => ['barb', 'barbie'],
        'patricia' => ['pat', 'patty', 'tricia', 'trish'],
        'jennifer' => ['jen', 'jenny'],
        'susan' => ['sue', 'susie', 'suzy'],
        'mary' => ['molly', 'polly', 'mae', 'mamie'],
        'ann' => ['annie', 'anna', 'anne'],
        'linda' => ['lindy'],
        'dorothy' => ['dot', 'dotty', 'dottie', 'dolly', 'dora'],
        'helen' => ['nell', 'nellie', 'lena'],
        'deborah' => ['deb', 'debbie', 'debby'],
        'rebecca' => ['becky', 'becca'],
        'victoria' => ['vicky', 'tori'],
        'virginia' => ['ginny', 'ginger'],
        'eleanor' => ['nell', 'nellie', 'ellie'],
        'laura' => ['laurie'],
        'marjorie' => ['margie', 'marge'],
        'nancy' => ['nan', 'nana'],
        'sarah' => ['sally', 'sadie'],
    ];

    /**
     * Return the set of lowercase name tokens to treat as equivalent
     * to `$given`. Always includes `$given` itself. Includes known
     * nicknames in both directions (formal → nicknames and
     * nickname → formal).
     *
     * @return list<string>
     */
    public static function variantsFor(string $given): array
    {
        $normalized = strtolower(trim($given));
        if ($normalized === '') {
            return [];
        }

        $variants = [$normalized];

        if (isset(self::TABLE[$normalized])) {
            foreach (self::TABLE[$normalized] as $nickname) {
                $variants[] = $nickname;
            }
        }

        foreach (self::TABLE as $formal => $nicknames) {
            if (in_array($normalized, $nicknames, true)) {
                $variants[] = $formal;
                foreach ($nicknames as $alt) {
                    $variants[] = $alt;
                }
            }
        }

        return array_values(array_unique($variants));
    }
}
