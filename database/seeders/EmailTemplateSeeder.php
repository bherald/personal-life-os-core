<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Seed default email templates using direct SQL
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'meeting_confirmation',
                'subject' => 'Meeting Confirmation: {{meeting_topic}}',
                'body' => "Hi {{name}},\n\nThis confirms our meeting on {{date}} at {{time}}.\n\nTopic: {{meeting_topic}}\nLocation: {{location}}\n\nLooking forward to it!\n\nBest regards",
                'variables' => json_encode(['name', 'date', 'time', 'meeting_topic', 'location']),
                'category' => 'work',
                'description' => 'Confirm meeting appointments',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'thank_you',
                'subject' => 'Thank you!',
                'body' => "Hi {{name}},\n\nThank you for {{reason}}. I really appreciate it!\n\nBest regards",
                'variables' => json_encode(['name', 'reason']),
                'category' => 'personal',
                'description' => 'Simple thank you note',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'follow_up',
                'subject' => 'Following up on {{topic}}',
                'body' => "Hi {{name}},\n\nI wanted to follow up on {{topic}}. {{additional_info}}\n\nPlease let me know your thoughts.\n\nBest regards",
                'variables' => json_encode(['name', 'topic', 'additional_info']),
                'category' => 'work',
                'description' => 'Follow up on previous conversation',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'out_of_office',
                'subject' => 'Out of Office: {{date_range}}',
                'body' => "Thank you for your email.\n\nI am currently out of the office from {{start_date}} to {{end_date}}.\n\nFor urgent matters, please contact {{backup_contact}}.\n\nI will respond to your email upon my return.\n\nBest regards",
                'variables' => json_encode(['date_range', 'start_date', 'end_date', 'backup_contact']),
                'category' => 'work',
                'description' => 'Automatic out of office reply',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'status_update',
                'subject' => 'Status Update: {{project_name}}',
                'body' => "Hi Team,\n\nHere's the status update for {{project_name}}:\n\nCompleted:\n{{completed_items}}\n\nIn Progress:\n{{in_progress_items}}\n\nNext Steps:\n{{next_steps}}\n\nBest regards",
                'variables' => json_encode(['project_name', 'completed_items', 'in_progress_items', 'next_steps']),
                'category' => 'work',
                'description' => 'Project status update',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'quick_reply_affirmative',
                'subject' => 'Re: {{original_subject}}',
                'body' => "Yes, that works for me. Thank you!",
                'variables' => json_encode(['original_subject']),
                'category' => 'personal',
                'description' => 'Quick affirmative reply',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'request_more_info',
                'subject' => 'Re: {{original_subject}}',
                'body' => "Hi {{name}},\n\nCould you please provide more information about {{topic}}?\n\nSpecifically, I'd like to know about:\n- {{detail_1}}\n- {{detail_2}}\n\nThank you!",
                'variables' => json_encode(['original_subject', 'name', 'topic', 'detail_1', 'detail_2']),
                'category' => 'work',
                'description' => 'Request additional information or clarification',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'decline_politely',
                'subject' => 'Re: {{original_subject}}',
                'body' => "Hi {{name}},\n\nThank you for reaching out. Unfortunately, I won't be able to {{request}} at this time.\n\n{{reason}}\n\nI appreciate your understanding.\n\nBest regards",
                'variables' => json_encode(['original_subject', 'name', 'request', 'reason']),
                'category' => 'personal',
                'description' => 'Politely decline a request',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Use insert to add all templates at once (more efficient than loop)
        DB::table('email_templates')->insert($templates);

        echo "✓ Seeded " . count($templates) . " email templates\n";
    }
}
