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
        'william' => ['will', 'bill', 'billy', 'willy', 'willie'],
        'richard' => ['rick', 'dick', 'rich', 'ricky'],
        'james' => ['jim', 'jimmy', 'jamie', 'jimbo'],
        'john' => ['jack', 'johnny', 'jonny'],
        'thomas' => ['tom', 'tommy'],
        'charles' => ['charlie', 'chuck', 'chas'],
        'edward' => ['ed', 'eddie', 'eddy', 'ned', 'ted'],
        'henry' => ['hank', 'harry'],
        'david' => ['dave', 'davey', 'davy'],
        'daniel' => ['dan', 'danny'],
        'joseph' => ['joe', 'joey'],
        'anthony' => ['tony'],
        'benjamin' => ['ben', 'benny', 'benji'],
        'alexander' => ['alex', 'al', 'xander'],
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
        'elizabeth' => ['liz', 'beth', 'betty', 'betsy', 'liza', 'eliza', 'lisa', 'libby'],
        'margaret' => ['maggie', 'meg', 'peggy', 'madge', 'greta'],
        'catherine' => ['cathy', 'kate', 'katie', 'cate', 'kat'],
        'katherine' => ['kathy', 'kate', 'katie', 'kat'],
        'barbara' => ['barb', 'barbie'],
        'patricia' => ['pat', 'patty', 'tricia', 'trish'],
        'jennifer' => ['jen', 'jenny'],
        'susan' => ['sue', 'susie'],
        'mary' => ['molly', 'polly', 'mae', 'mamie'],
        'linda' => ['lindy'],
        'dorothy' => ['dot', 'dottie', 'dolly', 'dora'],
        'helen' => ['nell', 'nellie', 'lena'],
        'deborah' => ['deb', 'debbie'],
        'rebecca' => ['becky', 'becca'],
        'victoria' => ['vicky', 'tori'],
        'virginia' => ['ginny', 'ginger'],
        'eleanor' => ['nell', 'nellie', 'ellie'],
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
