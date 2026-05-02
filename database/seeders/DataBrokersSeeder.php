<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * E06: Data Broker Seed Data
 *
 * Seeds the initial list of ~50 data brokers for the Personal Data Removal System.
 * These are well-known people search and data aggregation sites that commonly
 * expose personal information.
 */
class DataBrokersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $brokers = [
            // ========================================
            // PEOPLE SEARCH SITES (High Priority)
            // ========================================
            [
                'name' => 'Spokeo',
                'domain' => 'spokeo.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.spokeo.com/optout',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'BeenVerified',
                'domain' => 'beenverified.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.beenverified.com/faq/opt-out/',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'WhitePages',
                'domain' => 'whitepages.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.whitepages.com/suppression-requests',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'PeopleFinder',
                'domain' => 'peoplefinder.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.peoplefinder.com/optout',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'TruePeopleSearch',
                'domain' => 'truepeoplesearch.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.truepeoplesearch.com/removal',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'FastPeopleSearch',
                'domain' => 'fastpeoplesearch.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.fastpeoplesearch.com/removal',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'ThatsThem',
                'domain' => 'thatsthem.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://thatsthem.com/optout',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'USPhonebook',
                'domain' => 'usphonebook.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.usphonebook.com/opt-out',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Radaris',
                'domain' => 'radaris.com',
                'category' => 'people_search',
                'removal_method' => 'email',
                'removal_email' => 'support@radaris.com',
                'removal_url' => 'https://radaris.com/control/privacy',
                'automation_tier' => 2,
                'requires_captcha' => false,
                'requires_auth' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Pipl',
                'domain' => 'pipl.com',
                'category' => 'people_search',
                'removal_method' => 'email',
                'removal_email' => 'support@pipl.com',
                'automation_tier' => 3,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'PeopleLooker',
                'domain' => 'peoplelooker.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.peoplelooker.com/optout',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Zabasearch',
                'domain' => 'zabasearch.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.zabasearch.com/block_records/',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'AnyWho',
                'domain' => 'anywho.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.anywho.com/optout',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Addresses.com',
                'domain' => 'addresses.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.addresses.com/optout',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'PrivateEye',
                'domain' => 'privateeye.com',
                'category' => 'people_search',
                'removal_method' => 'email',
                'removal_email' => 'support@privateeye.com',
                'automation_tier' => 3,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'ClustrMaps',
                'domain' => 'clustrmaps.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://clustrmaps.com/bl/opt-out',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => false,
            ],
            [
                'name' => 'Nuwber',
                'domain' => 'nuwber.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://nuwber.com/removal/link',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'SearchPeopleFree',
                'domain' => 'searchpeoplefree.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.searchpeoplefree.com/opt-out',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'USSearch',
                'domain' => 'ussearch.com',
                'category' => 'people_search',
                'removal_method' => 'email',
                'removal_email' => 'privacy@ussearch.com',
                'automation_tier' => 2,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'PeekYou',
                'domain' => 'peekyou.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.peekyou.com/about/contact/optout/',
                'automation_tier' => 1,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],

            // ========================================
            // BACKGROUND CHECK SITES
            // ========================================
            [
                'name' => 'Intelius',
                'domain' => 'intelius.com',
                'category' => 'background_check',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.intelius.com/opt-out/submit/',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'InstantCheckmate',
                'domain' => 'instantcheckmate.com',
                'category' => 'background_check',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.instantcheckmate.com/opt-out/',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'TruthFinder',
                'domain' => 'truthfinder.com',
                'category' => 'background_check',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.truthfinder.com/opt-out/',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'CheckPeople',
                'domain' => 'checkpeople.com',
                'category' => 'background_check',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.checkpeople.com/opt-out',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'PublicRecordsNow',
                'domain' => 'publicrecordsnow.com',
                'category' => 'background_check',
                'removal_method' => 'email',
                'removal_email' => 'support@publicrecordsnow.com',
                'automation_tier' => 2,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'BackgroundCheck.run',
                'domain' => 'backgroundcheck.run',
                'category' => 'background_check',
                'removal_method' => 'email',
                'removal_email' => 'optout@backgroundcheck.run',
                'automation_tier' => 2,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],

            // ========================================
            // DATA AGGREGATORS
            // ========================================
            [
                'name' => 'Acxiom',
                'domain' => 'acxiom.com',
                'category' => 'data_aggregator',
                'removal_method' => 'web_form',
                'removal_url' => 'https://isapps.acxiom.com/optout/optout.aspx',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Epsilon',
                'domain' => 'epsilon.com',
                'category' => 'data_aggregator',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.epsilon.com/privacy-policy',
                'automation_tier' => 3,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Oracle Data Cloud',
                'domain' => 'oracle.com',
                'category' => 'data_aggregator',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.oracle.com/legal/privacy/marketing-cloud-data-cloud-privacy-policy.html',
                'automation_tier' => 3,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'LexisNexis',
                'domain' => 'lexisnexis.com',
                'category' => 'data_aggregator',
                'removal_method' => 'postal',
                'removal_url' => 'https://optout.lexisnexis.com/',
                'automation_tier' => 3,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],

            // ========================================
            // MARKETING SITES
            // ========================================
            [
                'name' => 'MyLife',
                'domain' => 'mylife.com',
                'category' => 'marketing',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.mylife.com/privacy-policy',
                'automation_tier' => 3,
                'requires_captcha' => true,
                'requires_auth' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Infotracer',
                'domain' => 'infotracer.com',
                'category' => 'marketing',
                'removal_method' => 'web_form',
                'removal_url' => 'https://infotracer.com/optout/',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Spydialer',
                'domain' => 'spydialer.com',
                'category' => 'marketing',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.spydialer.com/optout.aspx',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Neighbor.Report',
                'domain' => 'neighbor.report',
                'category' => 'marketing',
                'removal_method' => 'email',
                'removal_email' => 'info@neighbor.report',
                'automation_tier' => 2,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'FamilyTreeNow',
                'domain' => 'familytreenow.com',
                'category' => 'marketing',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.familytreenow.com/optout',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],

            // ========================================
            // OTHER PEOPLE SEARCH SITES
            // ========================================
            [
                'name' => 'VoterRecords',
                'domain' => 'voterrecords.com',
                'category' => 'other',
                'removal_method' => 'web_form',
                'removal_url' => 'https://voterrecords.com/optout',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Cubib',
                'domain' => 'cubib.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://cubib.com/optout/',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'PublicRecords360',
                'domain' => 'publicrecords360.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.publicrecords360.com/optout.html',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'CyberBackgroundChecks',
                'domain' => 'cyberbackgroundchecks.com',
                'category' => 'background_check',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.cyberbackgroundchecks.com/removal',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Advanced Background Checks',
                'domain' => 'advancedbackgroundchecks.com',
                'category' => 'background_check',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.advancedbackgroundchecks.com/removal',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Yasni',
                'domain' => 'yasni.com',
                'category' => 'people_search',
                'removal_method' => 'email',
                'removal_email' => 'privacy@yasni.com',
                'automation_tier' => 2,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'ZoomInfo',
                'domain' => 'zoominfo.com',
                'category' => 'marketing',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.zoominfo.com/about-zoominfo/privacy-manage-profile',
                'automation_tier' => 2,
                'requires_captcha' => true,
                'uses_javascript' => true,
            ],
            [
                'name' => 'CocoFinder',
                'domain' => 'cocofinder.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://cocofinder.com/remove-my-info',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'IDTrue',
                'domain' => 'idtrue.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://www.idtrue.com/optout/',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'OfficialUSA',
                'domain' => 'officialusa.com',
                'category' => 'people_search',
                'removal_method' => 'email',
                'removal_email' => 'remove@officialusa.com',
                'automation_tier' => 2,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Verecor',
                'domain' => 'verecor.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://verecor.com/ng/control/privacy',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
            [
                'name' => 'Pub360',
                'domain' => 'pub360.com',
                'category' => 'people_search',
                'removal_method' => 'web_form',
                'removal_url' => 'https://pub360.com/ng/control/privacy',
                'automation_tier' => 1,
                'requires_captcha' => false,
                'uses_javascript' => true,
            ],
        ];

        foreach ($brokers as $broker) {
            // Set defaults
            $broker['discovered_by'] = 'seed_list';
            $broker['is_active'] = true;
            $broker['created_at'] = $now;
            $broker['updated_at'] = $now;

            // Set defaults for optional fields
            $broker['removal_url'] = $broker['removal_url'] ?? null;
            $broker['removal_email'] = $broker['removal_email'] ?? null;
            $broker['requires_captcha'] = $broker['requires_captcha'] ?? false;
            $broker['requires_auth'] = $broker['requires_auth'] ?? false;
            $broker['uses_javascript'] = $broker['uses_javascript'] ?? true;
            $broker['rate_limit_seconds'] = $broker['rate_limit_seconds'] ?? 60;
            $broker['success_rate'] = 0.00;
            $broker['avg_removal_days'] = 0;
            $broker['total_attempts'] = 0;
            $broker['total_successes'] = 0;

            // Use raw SQL to insert
            $sql = "INSERT INTO data_brokers
                    (name, domain, category, removal_method, removal_url, removal_email,
                     automation_tier, requires_captcha, requires_auth, uses_javascript,
                     rate_limit_seconds, discovered_by, is_active, success_rate,
                     avg_removal_days, total_attempts, total_successes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE name = name";

            DB::insert($sql, [
                $broker['name'],
                $broker['domain'],
                $broker['category'],
                $broker['removal_method'],
                $broker['removal_url'],
                $broker['removal_email'],
                $broker['automation_tier'],
                $broker['requires_captcha'],
                $broker['requires_auth'],
                $broker['uses_javascript'],
                $broker['rate_limit_seconds'],
                $broker['discovered_by'],
                $broker['is_active'],
                $broker['success_rate'],
                $broker['avg_removal_days'],
                $broker['total_attempts'],
                $broker['total_successes'],
                $broker['created_at'],
                $broker['updated_at'],
            ]);
        }

        $this->command->info('Seeded ' . count($brokers) . ' data brokers.');
    }
}
