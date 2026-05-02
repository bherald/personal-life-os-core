<?php

namespace App\Nodes\YouTube;

use App\Controllers\NotificationController;
use App\Nodes\BaseNode;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Preview Notification Node
 *
 * Sends a preview notification via Pushover showing what will be processed.
 * Gives user a chance to cancel within the preview window.
 */
class PreviewNotification extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            // Get configuration
            $notifyVia = $this->getConfigValue('notify_via', 'pushover');
            $previewWindowMinutes = $this->getConfigValue('preview_window', 60);
            $messageTemplate = $this->getConfigValue('message', 'Found {count} videos to process. Cancel within {window} minutes.');

            // Extract videos from input
            $videos = $input['data']['videos'] ?? $input['videos'] ?? [];
            $count = count($videos);

            Log::info('PreviewNotification: Starting execution', [
                'video_count' => $count,
                'notify_via' => $notifyVia,
                'preview_window_minutes' => $previewWindowMinutes,
            ]);

            // If no videos, skip notification
            if ($count === 0) {
                Log::info('PreviewNotification: No videos to preview');

                return $this->standardOutput($input, [
                    'notification_sent' => false,
                    'reason' => 'No videos to preview',
                ]);
            }

            // Build notification message
            $message = $this->buildMessage($videos, $messageTemplate, $previewWindowMinutes);

            // Send notification based on method
            $sent = match ($notifyVia) {
                'pushover' => $this->sendPushoverNotification($message, $videos),
                default => throw new Exception("Unsupported notification method: {$notifyVia}")
            };

            if ($sent) {
                Log::info('PreviewNotification: Notification sent successfully');
            }

            // Pass through input to next node
            return $this->standardOutput($input, [
                'notification_sent' => $sent,
                'preview_window_minutes' => $previewWindowMinutes,
                'videos_count' => $count,
            ]);

        } catch (Exception $e) {
            Log::error('PreviewNotification: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't fail the workflow, just log the error
            return $this->standardOutput($input, [
                'notification_sent' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build notification message
     */
    private function buildMessage(array $videos, string $template, int $windowMinutes): string
    {
        $count = count($videos);

        // Replace template variables
        $message = str_replace(
            ['{count}', '{window}'],
            [$count, $windowMinutes],
            $template
        );

        // Add video list preview (first 5)
        $message .= "\n\nVideos:";
        $previewVideos = array_slice($videos, 0, 5);

        foreach ($previewVideos as $index => $video) {
            $title = $video['title'] ?? 'Unknown';
            $channel = $video['channel_title'] ?? 'Unknown';
            $message .= "\n".($index + 1).". {$title} ({$channel})";
        }

        if ($count > 5) {
            $message .= "\n... and ".($count - 5).' more';
        }

        return $message;
    }

    /**
     * Send Pushover notification
     */
    private function sendPushoverNotification(string $message, array $videos): bool
    {
        try {
            $controller = new NotificationController;
            $success = $controller->send('pushover', [
                'source_group' => 'workflow_node_notifications',
                'title' => '📺 YouTube Processing Preview',
                'message' => $message,
                'priority' => 0,
            ]);

            if ($success) {
                Log::info('PreviewNotification: Pushover notification sent', [
                    'video_count' => count($videos),
                ]);
            }

            return $success;

        } catch (Exception $e) {
            Log::error('PreviewNotification: Failed to send Pushover notification', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
