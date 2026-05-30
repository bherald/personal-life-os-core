/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `adaptive_mode_selections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `adaptive_mode_selections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `task_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selected_mode` enum('agentic','hybrid','deterministic') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `confidence` decimal(4,3) NOT NULL DEFAULT '0.000',
  `reasoning` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `was_fallback` tinyint(1) NOT NULL DEFAULT '0',
  `fallback_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `outcome_success` tinyint(1) DEFAULT NULL,
  `outcome_duration_ms` int unsigned DEFAULT NULL,
  `outcome_tokens` int unsigned DEFAULT NULL,
  `outcome_accuracy` tinyint unsigned DEFAULT NULL,
  `outcome_completeness` tinyint unsigned DEFAULT NULL,
  `outcome_relevance` tinyint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent_task` (`agent_id`,`task_key`),
  KEY `idx_agent_mode` (`agent_id`,`selected_mode`),
  KEY `idx_created` (`created_at`),
  KEY `idx_fallback` (`was_fallback`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_benchmarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_benchmarks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(64) NOT NULL,
  `agent_id` varchar(100) NOT NULL,
  `task_key` varchar(100) NOT NULL,
  `task_description` text NOT NULL,
  `workflow_mode` enum('agentic','hybrid','deterministic') NOT NULL,
  `tokens_used` int unsigned NOT NULL DEFAULT '0',
  `duration_ms` int unsigned NOT NULL DEFAULT '0',
  `iterations` int unsigned NOT NULL DEFAULT '0',
  `tool_calls_count` int unsigned NOT NULL DEFAULT '0',
  `tool_calls_detail` json DEFAULT NULL,
  `accuracy_score` tinyint unsigned DEFAULT NULL,
  `completeness_score` tinyint unsigned DEFAULT NULL,
  `relevance_score` tinyint unsigned DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `response_summary` text,
  `metadata` json DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '1',
  `error_message` text,
  `is_speculative` tinyint(1) NOT NULL DEFAULT '0',
  `spec_run_id` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `agent_id` (`agent_id`),
  KEY `task_key_workflow` (`task_key`,`workflow_mode`),
  KEY `run_id_workflow` (`run_id`,`workflow_mode`),
  KEY `idx_speculative` (`is_speculative`,`spec_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_episode_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_episode_summaries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(100) NOT NULL,
  `session_id` varchar(100) NOT NULL,
  `task` varchar(500) NOT NULL,
  `summary` text NOT NULL,
  `outcome` enum('success','failure','partial','error') NOT NULL DEFAULT 'success',
  `importance` decimal(3,2) NOT NULL DEFAULT '0.50',
  `tools_used` json DEFAULT NULL,
  `tool_count` smallint unsigned DEFAULT '0',
  `tokens_used` int unsigned DEFAULT '0',
  `duration_ms` int unsigned DEFAULT '0',
  `episode_count` smallint unsigned DEFAULT '0',
  `notes` text,
  `is_archived` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aes_session` (`session_id`),
  KEY `idx_aes_outcome` (`outcome`),
  KEY `idx_aes_agent_created` (`agent_id`,`created_at`),
  KEY `idx_aes_importance` (`importance` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_episodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_episodes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `skill_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SKILL.md version active during this episode',
  `session_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'task_started, task_completed, finding, error, handoff, observation',
  `summary` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` json DEFAULT NULL,
  `tokens_used` int unsigned DEFAULT '0',
  `duration_ms` int unsigned DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent_episodes_agent` (`agent_id`),
  KEY `idx_agent_episodes_session` (`session_id`),
  KEY `idx_agent_episodes_type` (`event_type`),
  KEY `idx_agent_episodes_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_execution_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_execution_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tool_call',
  `action_detail` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `risk_level` enum('read','write','destructive','blocked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context` json DEFAULT NULL,
  `outcome` enum('success','failure','denied','timeout','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `output_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `duration_ms` decimal(10,2) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent_session` (`session_id`),
  KEY `idx_agent_role` (`role`),
  KEY `idx_agent_created` (`created_at`),
  KEY `idx_ael_agent_name` (`agent_name`),
  KEY `idx_ael_action_type` (`action_type`),
  KEY `idx_ael_created_at` (`created_at`),
  KEY `idx_ael_risk_level` (`risk_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_handoff_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_handoff_agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `capabilities` json NOT NULL,
  `max_concurrent_handoffs` tinyint NOT NULL DEFAULT '5',
  `timeout_seconds` int NOT NULL DEFAULT '300',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_handoff_agents_agent_id_unique` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_handoff_contexts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_handoff_contexts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `handoff_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context_payload` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_handoff_contexts_handoff_id_unique` (`handoff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_handoff_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_handoff_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `handoff_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `success` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_handoff_id` (`handoff_id`),
  KEY `idx_handoff_roles` (`from_role`,`to_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_handoff_routing_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_handoff_routing_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_pattern` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `conditions` json DEFAULT NULL,
  `confidence` decimal(3,2) NOT NULL DEFAULT '0.90',
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_handoff_routing_rules_task_pattern_index` (`task_pattern`),
  KEY `agent_handoff_routing_rules_target_agent_id_index` (`target_agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_handoffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_handoffs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `handoff_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context_summary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'initiated',
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `duration_ms` int DEFAULT NULL,
  `result_summary` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_handoffs_handoff_id_unique` (`handoff_id`),
  KEY `agent_handoffs_source_agent_id_index` (`source_agent_id`),
  KEY `agent_handoffs_target_agent_id_index` (`target_agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_memory_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_memory_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` enum('episodic','procedural') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'episodic',
  `source_id` bigint unsigned NOT NULL,
  `target_type` enum('episodic','procedural') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'episodic',
  `target_id` bigint unsigned NOT NULL,
  `link_type` enum('related','extends','evolved_from') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'related',
  `strength` decimal(3,2) NOT NULL DEFAULT '0.50',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_type`,`source_id`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `idx_agent_source` (`agent_id`,`source_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `from_agent` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_agent` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '*' COMMENT 'Target agent or * for broadcast',
  `message_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'alert, finding, status_change, request, info',
  `subject` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL COMMENT 'Structured data payload',
  `priority` tinyint unsigned DEFAULT '0' COMMENT '0=normal, 1=high, 2=urgent',
  `acknowledged_by` json DEFAULT NULL COMMENT 'Array of agent IDs that have read this',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Auto-expire after TTL',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_to` (`to_agent`),
  KEY `idx_msg_from` (`from_agent`),
  KEY `idx_msg_type` (`message_type`),
  KEY `idx_msg_created` (`created_at`),
  KEY `idx_msg_priority` (`priority` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_procedures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_procedures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trigger_pattern` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'When to use this procedure',
  `action_sequence` json NOT NULL COMMENT 'Steps: [{tool, params, expected_output}]',
  `strategy_insight` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `procedure_type` enum('success','failure') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success' COMMENT 'success=do this, failure=avoid this',
  `source_session_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Session that originated this procedure',
  `is_canonical` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Promoted to canonical after proven reliability',
  `is_shared` tinyint(1) NOT NULL DEFAULT '0',
  `is_retired` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Retired: stale or low-performing',
  `success_rate` decimal(5,4) DEFAULT '1.0000',
  `times_used` int unsigned DEFAULT '0',
  `times_succeeded` int unsigned DEFAULT '0',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent_procedures_agent` (`agent_id`),
  KEY `idx_agent_procedures_success` (`success_rate` DESC),
  KEY `idx_agent_procedures_type` (`procedure_type`),
  KEY `idx_agent_procedures_retired` (`is_retired`),
  KEY `idx_agent_procedures_canonical` (`is_canonical`),
  KEY `idx_shared_active` (`is_shared`,`is_retired`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_recursion_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_recursion_calls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned DEFAULT NULL,
  `service_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_call_id` bigint unsigned DEFAULT NULL,
  `depth` int unsigned NOT NULL DEFAULT '0',
  `strategy` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `output_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `novelty_score` decimal(5,4) DEFAULT NULL,
  `tokens_used` int unsigned DEFAULT '0',
  `context_window_size` int unsigned DEFAULT '0',
  `provider_used` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `time_seconds` decimal(8,2) DEFAULT '0.00',
  `cost_usd` decimal(8,4) DEFAULT '0.0000',
  `move_on_triggered` tinyint(1) DEFAULT '0',
  `move_on_reason` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_arc_session` (`session_id`),
  KEY `idx_arc_service` (`service_name`),
  KEY `idx_arc_parent` (`parent_call_id`),
  KEY `idx_arc_depth` (`depth`),
  KEY `idx_arc_created_covering` (`created_at`,`tokens_used`,`move_on_triggered`),
  CONSTRAINT `agent_recursion_calls_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `agent_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_recursion_calls_ibfk_2` FOREIGN KEY (`parent_call_id`) REFERENCES `agent_recursion_calls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_review_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_review_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `review_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'finding, action, suggestion, alert',
  `finding_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` json DEFAULT NULL COMMENT 'Full context: evidence, sources, confidence scores',
  `confidence` decimal(3,2) DEFAULT NULL COMMENT '0.00-1.00 agent confidence score',
  `priority` tinyint unsigned DEFAULT '0' COMMENT '0=normal, 1=high, 2=urgent',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending' COMMENT 'pending, approved, rejected, expired',
  `reviewer_notes` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Auto-expire pending items after TTL',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `pending_dedup_key` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (if((`status` = _utf8mb4'pending'),concat(`agent_id`,_utf8mb4'|',`review_type`,_utf8mb4'|',left(ifnull(`title`,_utf8mb4''),80)),NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_arq_pending_dedup` (`pending_dedup_key`),
  KEY `idx_review_agent` (`agent_id`),
  KEY `idx_review_status` (`status`),
  KEY `idx_review_priority` (`priority` DESC),
  KEY `idx_review_token` (`token`),
  KEY `idx_review_created` (`created_at`),
  KEY `idx_review_queue_finding_type` (`finding_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_review_queue_archive_20260411`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_review_queue_archive_20260411` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `review_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'finding, action, suggestion, alert',
  `finding_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` json DEFAULT NULL COMMENT 'Full context: evidence, sources, confidence scores',
  `confidence` decimal(3,2) DEFAULT NULL COMMENT '0.00-1.00 agent confidence score',
  `priority` tinyint unsigned DEFAULT '0' COMMENT '0=normal, 1=high, 2=urgent',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending' COMMENT 'pending, approved, rejected, expired',
  `reviewer_notes` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Auto-expire pending items after TTL',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_agent` (`agent_id`),
  KEY `idx_review_status` (`status`),
  KEY `idx_review_priority` (`priority` DESC),
  KEY `idx_review_token` (`token`),
  KEY `idx_review_created` (`created_at`),
  KEY `idx_review_queue_finding_type` (`finding_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_semantic_fact_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_semantic_fact_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `memory_id` bigint unsigned NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_id` int unsigned DEFAULT NULL,
  `confidence` decimal(5,4) DEFAULT '0.5000',
  `agent_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_memory` (`memory_id`),
  KEY `idx_agent` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_semantic_memory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_semantic_memory` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int unsigned NOT NULL,
  `fact_type` varchar(50) NOT NULL,
  `fact_key` varchar(100) NOT NULL,
  `fact_value` text NOT NULL,
  `confidence` decimal(5,4) NOT NULL DEFAULT '0.5000',
  `consensus_status` enum('agreed','disputed','evolving') NOT NULL DEFAULT 'agreed',
  `source_count` int unsigned NOT NULL DEFAULT '0',
  `last_challenged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_fact_key` (`fact_key`),
  KEY `idx_consensus` (`consensus_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `workflow_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'chat',
  `agent_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skill_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SKILL.md version used for this session',
  `messages` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `context` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `agent_state` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `total_tokens` int unsigned NOT NULL DEFAULT '0',
  `message_count` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_sessions_session_id_unique` (`session_id`),
  KEY `agent_sessions_user_id_index` (`user_id`),
  KEY `agent_sessions_workflow_id_index` (`workflow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_tool_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_tool_registry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `service_class` varchar(255) NOT NULL,
  `method` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `parameters` json DEFAULT NULL,
  `returns_description` varchar(500) DEFAULT NULL,
  `permissions` json DEFAULT NULL,
  `risk_level` enum('read','write','destructive','blocked') NOT NULL DEFAULT 'read',
  `category` varchar(50) DEFAULT NULL,
  `requires_confirmation` tinyint(1) NOT NULL DEFAULT '0',
  `max_calls_per_run` int unsigned DEFAULT NULL,
  `mcp_server` varchar(50) DEFAULT NULL,
  `mcp_tool` varchar(100) DEFAULT NULL,
  `max_tokens_per_call` int unsigned DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `source` enum('config','manual','agent_proposed','composed') DEFAULT 'manual',
  `proposed_by` varchar(100) DEFAULT NULL COMMENT 'Agent ID that proposed this tool',
  `approved_by` varchar(100) DEFAULT NULL COMMENT 'User or agent that approved',
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_source` (`source`),
  KEY `idx_risk_level` (`risk_level`),
  KEY `idx_mcp_server` (`mcp_server`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_prompts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_prompts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prompt_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique identifier for the prompt',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable name',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Description of when/how this prompt is used',
  `prompt_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The actual prompt text',
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'file_organizer' COMMENT 'Category grouping',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether this prompt is in use',
  `used_in_service` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Service class that uses this prompt',
  `used_in_line` int DEFAULT NULL COMMENT 'Line number in service for reference',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_prompts_prompt_key_unique` (`prompt_key`),
  KEY `ai_prompts_category_index` (`category`),
  KEY `ai_prompts_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bias_rating_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bias_rating_aliases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `canonical_source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bias_rating_aliases_alias_unique` (`alias`),
  KEY `bias_rating_aliases_canonical_source_active_index` (`canonical_source`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bias_ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bias_ratings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `news_source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rating` enum('left','left-center','center','right-center','right','allsides') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rating_num` tinyint DEFAULT NULL,
  `data_source` enum('allsides','mbfc','both','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allsides',
  `mbfc_factual_rating` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mbfc_credibility_score` int DEFAULT NULL,
  `is_polarizing_source` tinyint(1) NOT NULL DEFAULT '0',
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agree` int DEFAULT NULL,
  `disagree` int DEFAULT NULL,
  `perc_agree` decimal(5,4) DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `editorial_review` tinyint(1) NOT NULL DEFAULT '0',
  `blind_survey` tinyint(1) NOT NULL DEFAULT '0',
  `third_party_analysis` tinyint(1) NOT NULL DEFAULT '0',
  `independent_research` tinyint(1) NOT NULL DEFAULT '0',
  `confidence_level` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twitter` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wiki` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `screen_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bias_ratings_news_source_unique` (`news_source`),
  KEY `bias_ratings_rating_index` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `broker_health_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `broker_health_checks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `data_broker_id` int unsigned NOT NULL,
  `check_type` enum('optout_page','form_validation','api_response') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('healthy','degraded','broken','changed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'healthy',
  `response_code` int DEFAULT NULL,
  `response_time_ms` int DEFAULT NULL,
  `details` json DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_health_broker` (`data_broker_id`),
  KEY `idx_health_status` (`status`),
  KEY `idx_health_checked` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nextcloud UID',
  `calendar_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `location` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime DEFAULT NULL,
  `all_day` tinyint(1) DEFAULT '0',
  `recurrence_rule` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attendees` json DEFAULT NULL,
  `raw_ical` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_external_id` (`external_id`),
  KEY `idx_start_at` (`start_at`),
  KEY `idx_calendar` (`calendar_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `role` enum('user','assistant','system') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tool_calls` json DEFAULT NULL,
  `tokens` int DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_messages_conversation_id_created_at_index` (`conversation_id`,`created_at`),
  CONSTRAINT `chat_messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `compensation_handlers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `compensation_handlers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `node_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Node type this handler compensates (e.g., EmailNode)',
  `handler_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Fully qualified class name or method name',
  `config` json DEFAULT NULL COMMENT 'Handler-specific configuration',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_node_type_active` (`node_type`,`active`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `composite_tool_usage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `composite_tool_usage` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tool_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `times_executed` int unsigned NOT NULL DEFAULT '0',
  `times_succeeded` int unsigned NOT NULL DEFAULT '0',
  `times_failed` int unsigned NOT NULL DEFAULT '0',
  `avg_duration_ms` int unsigned NOT NULL DEFAULT '0',
  `last_executed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tool_name` (`tool_name`),
  KEY `idx_tool_name` (`tool_name`),
  KEY `idx_last_executed` (`last_executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `compute_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `compute_instances` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `host` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ssh_user` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_local` tinyint(1) NOT NULL DEFAULT '0',
  `gpu_model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gpu_vram_mb` int unsigned DEFAULT NULL,
  `python_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'python3',
  `scripts_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `capabilities` json NOT NULL,
  `priority` tinyint unsigned NOT NULL DEFAULT '50',
  `health_score` tinyint unsigned NOT NULL DEFAULT '100',
  `circuit_state` enum('closed','open','half_open') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'closed',
  `circuit_opened_at` timestamp NULL DEFAULT NULL,
  `circuit_retry_at` timestamp NULL DEFAULT NULL,
  `max_concurrent` tinyint unsigned NOT NULL DEFAULT '1',
  `config` json DEFAULT NULL,
  `avg_execution_ms` decimal(10,2) DEFAULT NULL,
  `total_executions` int unsigned NOT NULL DEFAULT '0',
  `total_failures` int unsigned NOT NULL DEFAULT '0',
  `consecutive_failures` tinyint unsigned NOT NULL DEFAULT '0',
  `success_rate` decimal(5,2) DEFAULT NULL,
  `shares_gpu_with_llm` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_healthy` tinyint(1) NOT NULL DEFAULT '1',
  `last_health_check` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instance_id` (`instance_id`),
  KEY `idx_capability_active` (`is_active`,`is_healthy`),
  KEY `idx_circuit` (`circuit_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nextcloud UID',
  `full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nickname` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emails` json DEFAULT NULL COMMENT '[{type: work, email: ...}, ...]',
  `phones` json DEFAULT NULL COMMENT '[{type: mobile, number: ...}, ...]',
  `addresses` json DEFAULT NULL,
  `organization` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `photo_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `categories` json DEFAULT NULL COMMENT 'tags/groups',
  `raw_vcard` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_external_id` (`external_id`),
  KEY `idx_full_name` (`full_name`),
  KEY `idx_organization` (`organization`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ollama:llama3.1:8b-instruct-q5_K_M',
  `system_prompt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_private` tinyint(1) NOT NULL DEFAULT '0',
  `model_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'standard',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversations_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_brokers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_brokers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('people_search','marketing','background_check','data_aggregator','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'people_search',
  `removal_method` enum('web_form','email','api','postal','phone','unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `removal_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `removal_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `required_fields` json DEFAULT NULL COMMENT 'JSON array of required fields: name, email, phone, address, city, state, zip, dob',
  `optional_fields` json DEFAULT NULL COMMENT 'JSON array of optional fields that may help with matching',
  `automation_tier` tinyint NOT NULL DEFAULT '3',
  `success_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `avg_removal_days` int NOT NULL DEFAULT '0',
  `total_attempts` int NOT NULL DEFAULT '0',
  `total_successes` int NOT NULL DEFAULT '0',
  `requires_captcha` tinyint(1) NOT NULL DEFAULT '0',
  `requires_auth` tinyint(1) NOT NULL DEFAULT '0',
  `uses_javascript` tinyint(1) NOT NULL DEFAULT '1',
  `form_config` json DEFAULT NULL,
  `discovered_selectors` json DEFAULT NULL,
  `removal_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `form_config_source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `form_config_updated_at` timestamp NULL DEFAULT NULL,
  `rate_limit_seconds` int NOT NULL DEFAULT '60',
  `discovered_by` enum('seed_list','ai_research','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'seed_list',
  `discovery_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `health_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `last_health_check` timestamp NULL DEFAULT NULL,
  `optout_page_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `badbool_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `data_brokers_domain_unique` (`domain`),
  KEY `data_brokers_is_active_index` (`is_active`),
  KEY `data_brokers_automation_tier_index` (`automation_tier`),
  KEY `data_brokers_category_index` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_subjects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zip` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `aliases` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `last_breach_check` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `data_subjects_is_active_index` (`is_active`),
  KEY `data_subjects_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `detected_bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `detected_bills` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `payee` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `bill_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `confidence` decimal(3,2) DEFAULT NULL,
  `email_date` timestamp NULL DEFAULT NULL,
  `email_id` int unsigned DEFAULT NULL,
  `status` enum('pending','paid','overdue','dismissed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bills_due_date` (`due_date`),
  KEY `idx_bills_status` (`status`),
  KEY `idx_bills_payee` (`payee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dev_agent_readiness_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dev_agent_readiness_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `captured_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `window_hours` smallint unsigned NOT NULL DEFAULT '24',
  `overall_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_count` smallint unsigned NOT NULL DEFAULT '0',
  `warning_count` smallint unsigned NOT NULL DEFAULT '0',
  `critical_count` smallint unsigned NOT NULL DEFAULT '0',
  `trace_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trace_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `trace_directory_writable` tinyint(1) NOT NULL DEFAULT '0',
  `trace_events_24h` int unsigned DEFAULT NULL,
  `trace_malformed_lines_24h` int unsigned DEFAULT NULL,
  `trace_scan_status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recursion_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recursion_calls_7d` int unsigned DEFAULT NULL,
  `checks_summary` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dars_captured_status` (`captured_at`,`overall_status`),
  KEY `idx_dars_trace_status_scan` (`trace_status`,`trace_scan_status`),
  KEY `dev_agent_readiness_snapshots_captured_at_index` (`captured_at`),
  KEY `dev_agent_readiness_snapshots_overall_status_index` (`overall_status`),
  KEY `dev_agent_readiness_snapshots_trace_status_index` (`trace_status`),
  KEY `dev_agent_readiness_snapshots_recursion_status_index` (`recursion_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `devops_commands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `devops_commands` (
  `id` int NOT NULL AUTO_INCREMENT,
  `command` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `safety_level` enum('green','yellow','red') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'red',
  `auto_execute` tinyint(1) NOT NULL DEFAULT '0',
  `conditions` json DEFAULT NULL COMMENT 'Conditions when to recommend (e.g., {"failed_jobs_gt": 0})',
  `last_executed_at` datetime DEFAULT NULL,
  `execution_count` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `command` (`command`),
  KEY `idx_safety_level` (`safety_level`),
  KEY `idx_auto_execute` (`auto_execute`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `distributed_agent_health`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `distributed_agent_health` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint unsigned NOT NULL,
  `cpu_usage` double DEFAULT NULL,
  `memory_usage` double DEFAULT NULL,
  `active_tasks` int unsigned NOT NULL DEFAULT '0',
  `avg_response_time_ms` double DEFAULT NULL,
  `tasks_per_minute` int unsigned NOT NULL DEFAULT '0',
  `custom_metrics` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `recorded_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `distributed_agent_health_agent_id_foreign` (`agent_id`),
  CONSTRAINT `distributed_agent_health_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `distributed_agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `distributed_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `distributed_agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `node_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `capabilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `max_concurrent_tasks` int unsigned NOT NULL DEFAULT '5',
  `current_load` int unsigned NOT NULL DEFAULT '0',
  `total_tasks_completed` int unsigned NOT NULL DEFAULT '0',
  `total_tasks_failed` int unsigned NOT NULL DEFAULT '0',
  `avg_task_duration_ms` double NOT NULL DEFAULT '0',
  `last_heartbeat_at` timestamp NULL DEFAULT NULL,
  `registered_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `distributed_agents_agent_id_unique` (`agent_id`),
  KEY `distributed_agents_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `distributed_task_batch_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `distributed_task_batch_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint unsigned NOT NULL,
  `task_id` bigint unsigned NOT NULL,
  `sequence_order` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `distributed_task_batch_items_batch_id_foreign` (`batch_id`),
  KEY `distributed_task_batch_items_task_id_foreign` (`task_id`),
  CONSTRAINT `distributed_task_batch_items_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `distributed_task_batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `distributed_task_batch_items_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `distributed_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `distributed_task_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `distributed_task_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_tasks` int unsigned NOT NULL DEFAULT '0',
  `completed_tasks` int unsigned NOT NULL DEFAULT '0',
  `failed_tasks` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `aggregated_results` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `options` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `distributed_task_batches_batch_id_unique` (`batch_id`),
  KEY `distributed_task_batches_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `distributed_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `distributed_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `required_capabilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `assigned_agent_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `priority` int NOT NULL DEFAULT '0',
  `retry_count` int unsigned NOT NULL DEFAULT '0',
  `max_retries` int unsigned NOT NULL DEFAULT '3',
  `result` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `timeout_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `distributed_tasks_task_id_unique` (`task_id`),
  KEY `distributed_tasks_task_type_index` (`task_type`),
  KEY `distributed_tasks_status_index` (`status`),
  KEY `distributed_tasks_assigned_agent_id_foreign` (`assigned_agent_id`),
  CONSTRAINT `distributed_tasks_assigned_agent_id_foreign` FOREIGN KEY (`assigned_agent_id`) REFERENCES `distributed_agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_credibility`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_credibility` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `credibility_score` decimal(4,3) NOT NULL DEFAULT '0.500',
  `tier` tinyint unsigned NOT NULL DEFAULT '3' COMMENT '1=authoritative, 2=major news, 3=reference, 4=general, 5=low credibility',
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'government, academic, wire_service, scientific, health, news, reference, factcheck, tabloid',
  `is_tld_pattern` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 if this is a TLD pattern like gov, edu, ac.uk',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`),
  KEY `idx_tier` (`tier`),
  KEY `idx_active_score` (`is_active`,`credibility_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_classifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_classifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `folder` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_date` timestamp NULL DEFAULT NULL,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `tags` json DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `confidence` double DEFAULT NULL,
  `needs_response` tinyint(1) NOT NULL DEFAULT '0',
  `has_dates` tinyint(1) NOT NULL DEFAULT '0',
  `is_bill` tinyint(1) NOT NULL DEFAULT '0',
  `is_shipping` tinyint(1) NOT NULL DEFAULT '0',
  `extracted_dates` json DEFAULT NULL,
  `extracted_amounts` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `processed` tinyint(1) NOT NULL DEFAULT '0',
  `processed_at` timestamp NULL DEFAULT NULL,
  `classified_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_classifications_category_priority_index` (`category`,`priority`),
  KEY `email_classifications_message_id_index` (`message_id`),
  KEY `email_classifications_folder_index` (`folder`),
  KEY `idx_email_classifications_created_at` (`created_at`),
  KEY `idx_email_classifications_cat_time` (`category`,`created_at`),
  KEY `email_classifications_from_address_index` (`from_address`),
  KEY `email_classifications_email_date_index` (`email_date`),
  KEY `email_classifications_is_bill_index` (`is_bill`),
  KEY `email_classifications_processed_index` (`processed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_domain_throttles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_domain_throttles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Email domain (e.g., gmail.com)',
  `delay_ms` int unsigned DEFAULT '0' COMMENT 'Delay in milliseconds between sends',
  `max_per_hour` int unsigned DEFAULT NULL COMMENT 'Max sends per hour to this domain',
  `max_per_day` int unsigned DEFAULT NULL COMMENT 'Max sends per day to this domain',
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Reason for throttle (bounce_rate, manual, etc)',
  `is_active` tinyint(1) DEFAULT '1',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'When throttle expires (NULL = permanent)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_domain` (`domain`),
  KEY `idx_active_expires` (`is_active`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_notification_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(255) DEFAULT NULL,
  `setting_value` json DEFAULT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_reply_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_reply_drafts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `original_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ai_reply',
  `to` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cc` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bcc` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ai_suggestions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `ai_confidence` double DEFAULT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `workflow_execution_id` bigint unsigned DEFAULT NULL,
  `related_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_reply_drafts_template_id_foreign` (`template_id`),
  KEY `email_reply_drafts_original_message_id_index` (`original_message_id`),
  KEY `email_reply_drafts_source_index` (`source`),
  KEY `email_reply_drafts_priority_index` (`priority`),
  KEY `email_reply_drafts_status_source_index` (`status`,`source`),
  KEY `email_reply_drafts_status_priority_index` (`status`,`priority`),
  KEY `email_reply_drafts_related_type_related_id_index` (`related_type`,`related_id`),
  CONSTRAINT `email_reply_drafts_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_settings_setting_key_unique` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_suggested_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_suggested_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `source_email_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_folder` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_date` timestamp NULL DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `action_data` json DEFAULT NULL,
  `contact_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_count` int NOT NULL DEFAULT '1',
  `event_date` timestamp NULL DEFAULT NULL,
  `event_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bill_from` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bill_amount` decimal(10,2) DEFAULT NULL,
  `bill_due_date` timestamp NULL DEFAULT NULL,
  `bill_account` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notified` tinyint(1) NOT NULL DEFAULT '0',
  `notified_at` timestamp NULL DEFAULT NULL,
  `confidence` double DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_suggested_actions_type_status_index` (`type`,`status`),
  KEY `email_suggested_actions_status_created_at_index` (`status`,`created_at`),
  KEY `email_suggested_actions_contact_email_index` (`contact_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables` json DEFAULT NULL,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_templates_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `embedding_training_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `embedding_training_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('data_ready','training','completed','deployed','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'data_ready',
  `model_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_model` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `training_file` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eval_file` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `output_dir` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `training_pairs` int unsigned DEFAULT NULL,
  `eval_pairs` int unsigned DEFAULT NULL,
  `epochs` int unsigned DEFAULT '3',
  `batch_size` int unsigned DEFAULT '32',
  `learning_rate` decimal(10,8) DEFAULT '0.00002000',
  `evaluation_metrics` json DEFAULT NULL,
  `deployed_model_tag` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `evaluated_at` datetime DEFAULT NULL,
  `deployed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_id` (`job_id`),
  KEY `idx_status` (`status`),
  KEY `idx_job_id` (`job_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `emotional_language_words`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `emotional_language_words` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `word` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sentiment` enum('positive','negative','sensational') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `intensity` int NOT NULL DEFAULT '1',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emotional_language_words_word_unique` (`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evidence_correlations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `evidence_correlations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL COMMENT 'Primary person being researched',
  `citation1_id` int unsigned NOT NULL,
  `citation2_id` int unsigned NOT NULL,
  `event_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','corroborates','conflicts','supplements','resolved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `correlation_score` tinyint unsigned DEFAULT NULL COMMENT 'Overall correlation score',
  `date_agreement` tinyint unsigned DEFAULT NULL COMMENT 'Date agreement score (0-100)',
  `place_agreement` tinyint unsigned DEFAULT NULL COMMENT 'Place agreement score (0-100)',
  `source_independence_score` tinyint unsigned DEFAULT NULL COMMENT 'Source independence score (0-100)',
  `analysis_details` json DEFAULT NULL COMMENT 'Full analysis breakdown',
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Explanation of how conflict was resolved',
  `preferred_citation_id` int unsigned DEFAULT NULL COMMENT 'Citation preferred in resolution',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `assessed_by` int unsigned DEFAULT NULL COMMENT 'User who performed assessment',
  `assessed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_unique_correlation` (`citation1_id`,`citation2_id`,`event_type`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_status` (`status`),
  KEY `idx_citation2` (`citation2_id`),
  KEY `idx_correlation_score` (`correlation_score`),
  KEY `idx_person_event` (`person_id`,`event_type`),
  KEY `idx_tree_status` (`tree_id`,`status`),
  KEY `fk_ec_preferred` (`preferred_citation_id`),
  CONSTRAINT `fk_ec_citation1` FOREIGN KEY (`citation1_id`) REFERENCES `genealogy_citations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ec_citation2` FOREIGN KEY (`citation2_id`) REFERENCES `genealogy_citations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ec_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ec_preferred` FOREIGN KEY (`preferred_citation_id`) REFERENCES `genealogy_citations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ec_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expected_outputs_catalog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expected_outputs_catalog` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scheduled_job_id` int unsigned DEFAULT NULL,
  `job_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expected_item` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_type` enum('table_row_recent','job_run_recent','log_pattern_recent') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_params` json NOT NULL,
  `freshness_window_minutes` int unsigned NOT NULL,
  `severity` enum('info','warn','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'warn',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enabled_severity` (`enabled`,`severity`),
  KEY `idx_job_name` (`job_name`),
  KEY `idx_scheduled_job_id` (`scheduled_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `extension_browse_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `extension_browse_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `status` enum('pending','in_progress','completed','failed','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `context` json DEFAULT NULL,
  `result` json DEFAULT NULL,
  `priority` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_browse_queue_status` (`status`),
  KEY `idx_browse_queue_domain` (`domain`),
  KEY `idx_browse_queue_priority` (`priority` DESC,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fan_cooccurrences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fan_cooccurrences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL COMMENT 'Primary person being researched',
  `tree_id` int unsigned NOT NULL,
  `cooccurring_name` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name that appeared alongside person_id',
  `cooccurring_surname` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (trim(substring_index(`cooccurring_name`,_utf8mb4' ',-(1)))) STORED,
  `source_type` enum('witness','census_neighbor','church','military','land','probate','newspaper','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `source_ref` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL or citation where co-occurrence was found',
  `source_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Date of the source document',
  `source_location` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Place of the source document',
  `occurrence_count` smallint unsigned NOT NULL DEFAULT '1' COMMENT 'Incremented when same name appears in same source_type again',
  `confidence` decimal(4,3) NOT NULL DEFAULT '0.700' COMMENT '1.0 = named witness/relative, 0.5 = nearby neighbor',
  `agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Agent that extracted this co-occurrence',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_name_type` (`person_id`,`cooccurring_name`(200),`source_type`) COMMENT 'Prevents duplicates; ON DUPLICATE KEY UPDATE increments occurrence_count',
  KEY `idx_tree_id` (`tree_id`),
  KEY `idx_surname` (`cooccurring_surname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_bundle_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_bundle_members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bundle_id` int unsigned NOT NULL,
  `file_registry_id` int unsigned NOT NULL,
  `role` enum('primary','sidecar','related') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'related',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bundle_file` (`bundle_id`,`file_registry_id`),
  KEY `idx_bundle_members_file` (`file_registry_id`),
  CONSTRAINT `fk_bundle_members_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `file_bundles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_bundles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `primary_file_id` int unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bundle_type` enum('raw_jpg','video_subtitle','document_set','photo_series') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `auto_detected` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bundles_primary` (`primary_file_id`),
  KEY `idx_bundles_type` (`bundle_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_collection_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_collection_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `collection_id` int unsigned NOT NULL,
  `file_registry_id` int unsigned NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collection_file` (`collection_id`,`file_registry_id`),
  CONSTRAINT `fk_collection_items_collection` FOREIGN KEY (`collection_id`) REFERENCES `file_collections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_collections` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `collection_type` enum('album','project','tag_group') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'album',
  `cover_image_uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_smart` tinyint(1) NOT NULL DEFAULT '0',
  `smart_criteria` json DEFAULT NULL,
  `item_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_collections_type` (`collection_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_quarantine`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_quarantine` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `file_registry_id` int unsigned DEFAULT NULL,
  `asset_uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` enum('suspicious','malformed','policy_violation','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `detected_by` enum('scan','ai','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `details` json DEFAULT NULL,
  `status` enum('quarantined','reviewed','released','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quarantined',
  `reviewed_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_quarantine_file` (`file_registry_id`),
  KEY `idx_quarantine_status` (`status`),
  KEY `idx_quarantine_reason` (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `asset_uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'UUID v4 - permanent reference for Joplin/RAG links',
  `nextcloud_fileid` bigint unsigned DEFAULT NULL COMMENT 'Nextcloud internal file ID - survives moves/renames',
  `current_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Current path in Nextcloud relative to user root',
  `path_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 of current_path for indexing',
  `original_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Original path when first discovered (D:master...)',
  `original_source` enum('windows_d_master','nextcloud','joplin_attachment','upload','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'windows_d_master',
  `filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT '0',
  `nextcloud_modified_at` timestamp NULL DEFAULT NULL,
  `content_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SHA-256 of file content',
  `content_hash_verified_at` timestamp NULL DEFAULT NULL COMMENT 'Last time hash was verified against actual file',
  `status` enum('active','orphaned','deleted','pending_verification') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `last_verified_at` timestamp NULL DEFAULT NULL COMMENT 'Last time file existence was verified',
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  `verification_failures` int DEFAULT '0' COMMENT 'Consecutive verification failures',
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Human-readable title (from AI or manual)',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `ai_tags` json DEFAULT NULL COMMENT 'AI-detected tags with confidence scores',
  `ai_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'AI-generated description of file contents',
  `ai_document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'AI classification: invoice, receipt, letter, photo, contract, etc.',
  `ai_detected_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ai_analyzed_at` timestamp NULL DEFAULT NULL COMMENT 'When AI analysis was last performed',
  `ai_analysis_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Model/pipeline version used for analysis',
  `date_taken` timestamp NULL DEFAULT NULL,
  `date_taken_source` enum('exif_original','exif_digitized','exif_modified','path_extracted','filename_extracted','ai_estimated','user_manual','file_modified','scan_exif','ai_visual_high','ai_visual_medium','ai_visual_low') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_taken_confidence` decimal(3,2) DEFAULT NULL,
  `date_taken_reasoning` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `scan_date` timestamp NULL DEFAULT NULL,
  `is_scan` tinyint NOT NULL DEFAULT '0',
  `scan_context` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_needs_review` tinyint NOT NULL DEFAULT '0',
  `date_extracted_at` timestamp NULL DEFAULT NULL,
  `exif_written` tinyint(1) NOT NULL DEFAULT '0',
  `exif_date_written_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `path_updated_at` timestamp NULL DEFAULT NULL COMMENT 'Last time current_path changed',
  `chunk_hashes` json DEFAULT NULL COMMENT 'FastCDC chunk hashes: [{offset, size, hash}, ...]',
  `chunk_algorithm` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'fastcdc' COMMENT 'Chunking algorithm used',
  `chunk_count` int unsigned DEFAULT NULL COMMENT 'Number of chunks',
  `chunked_at` timestamp NULL DEFAULT NULL COMMENT 'When chunking was performed',
  `thumbnail_generated_at` timestamp NULL DEFAULT NULL,
  `thumbnail_sizes` json DEFAULT NULL,
  `thumbnail_error` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quarantine_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `face_count` int unsigned DEFAULT NULL,
  `face_scan_at` timestamp NULL DEFAULT NULL,
  `exif_faces_written` tinyint NOT NULL DEFAULT '0',
  `exif_faces_written_at` timestamp NULL DEFAULT NULL,
  `exif_tags_written` tinyint NOT NULL DEFAULT '0',
  `exif_tags_written_at` timestamp NULL DEFAULT NULL,
  `exif_location_written` tinyint DEFAULT NULL,
  `metadata_synced_at` timestamp NULL DEFAULT NULL,
  `quarantine_reason` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quarantine_details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quarantined_at` timestamp NULL DEFAULT NULL,
  `quarantine_reviewed_at` timestamp NULL DEFAULT NULL,
  `quarantine_review_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `semantic_indexed_at` datetime DEFAULT NULL,
  `semantic_chunk_count` int unsigned DEFAULT '0',
  `phash_error` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exif_checked` tinyint(1) DEFAULT '0',
  `gps_latitude` decimal(10,8) DEFAULT NULL,
  `gps_longitude` decimal(11,8) DEFAULT NULL,
  `gps_location` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `camera_make` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `camera_model` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exif_rating` tinyint unsigned DEFAULT NULL,
  `exif_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `exif_caption` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `claim_worker` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_asset_uuid` (`asset_uuid`),
  UNIQUE KEY `uk_path_hash` (`path_hash`),
  KEY `idx_nextcloud_fileid` (`nextcloud_fileid`),
  KEY `idx_content_hash` (`content_hash`),
  KEY `idx_status` (`status`),
  KEY `idx_extension` (`extension`),
  KEY `idx_category` (`category`),
  KEY `idx_original_source` (`original_source`),
  KEY `file_registry_rag_indexed_at_index` (`rag_indexed_at`),
  KEY `file_registry_ai_document_type_index` (`ai_document_type`),
  KEY `file_registry_ai_analyzed_at_index` (`ai_analyzed_at`),
  KEY `idx_file_registry_chunked_at` (`chunked_at`),
  KEY `idx_file_registry_thumb_gen` (`thumbnail_generated_at`),
  KEY `file_registry_date_taken_index` (`date_taken`),
  KEY `file_registry_date_taken_source_index` (`date_taken_source`),
  KEY `file_registry_date_extracted_at_index` (`date_extracted_at`),
  KEY `idx_file_registry_video_hash` (`video_hash`),
  KEY `idx_date_writeback_pending` (`exif_written`,`date_taken`),
  KEY `idx_faces_writeback_pending` (`exif_faces_written`),
  KEY `idx_tags_writeback_pending` (`exif_tags_written`),
  KEY `idx_file_registry_current_path` (`current_path`(255)),
  KEY `idx_file_registry_filename` (`filename`),
  KEY `idx_file_registry_claim` (`claim_worker`,`claim_expires_at`),
  KEY `file_registry_is_scan_index` (`is_scan`),
  KEY `file_registry_date_needs_review_index` (`date_needs_review`),
  KEY `idx_gps_location` (`gps_latitude`,`gps_location`(20)),
  FULLTEXT KEY `ft_ai_search` (`ai_description`,`ai_detected_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_duplicates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_duplicates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `content_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `canonical_file_id` bigint unsigned NOT NULL COMMENT 'The chosen primary file',
  `duplicate_file_id` bigint unsigned NOT NULL COMMENT 'The duplicate file',
  `status` enum('pending_review','keep_both','merged','ignored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending_review',
  `reviewed_by` enum('ai','human') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_content_hash` (`content_hash`),
  KEY `idx_canonical_file_id` (`canonical_file_id`),
  KEY `idx_duplicate_file_id` (`duplicate_file_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_duplicates_canonical` FOREIGN KEY (`canonical_file_id`) REFERENCES `file_registry` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_duplicates_duplicate` FOREIGN KEY (`duplicate_file_id`) REFERENCES `file_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_faces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_faces` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `file_registry_id` bigint unsigned NOT NULL,
  `face_index` int DEFAULT '0',
  `person_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `genealogy_person_id` int unsigned DEFAULT NULL COMMENT 'Links to genealogy_persons if matched',
  `region_x` decimal(10,8) DEFAULT NULL COMMENT 'Normalized X coordinate (0-1)',
  `region_y` decimal(10,8) DEFAULT NULL COMMENT 'Normalized Y coordinate (0-1)',
  `region_w` decimal(10,8) DEFAULT NULL COMMENT 'Normalized width (0-1)',
  `region_h` decimal(10,8) DEFAULT NULL COMMENT 'Normalized height (0-1)',
  `confidence` decimal(5,2) DEFAULT NULL COMMENT 'Detection confidence 0-100',
  `source` enum('xmp','ai_detection','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'xmp',
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `embedding` json DEFAULT NULL,
  `detected_at` timestamp NULL DEFAULT NULL,
  `hidden` tinyint(1) NOT NULL DEFAULT '0',
  `favorite` tinyint(1) NOT NULL DEFAULT '0',
  `cluster_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_face_file_person_index` (`file_registry_id`,`face_index`,`person_name`),
  KEY `idx_face_genealogy` (`genealogy_person_id`),
  KEY `idx_frf_name_hidden` (`person_name`,`hidden`),
  KEY `idx_frf_cluster_hidden` (`cluster_id`,`hidden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_path_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_path_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `file_registry_id` bigint unsigned NOT NULL,
  `previous_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `new_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `moved_by` enum('ai_reorganize','human_manual','nextcloud_sync','system') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'system',
  `move_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `moved_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_file_registry_id` (`file_registry_id`),
  KEY `idx_moved_at` (`moved_at`),
  CONSTRAINT `fk_path_history_registry` FOREIGN KEY (`file_registry_id`) REFERENCES `file_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_perceptual_hashes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_perceptual_hashes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `file_registry_id` bigint unsigned NOT NULL,
  `dhash_hex` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dhash_int_hi` bigint unsigned NOT NULL COMMENT 'Upper 64 bits of dHash',
  `dhash_int_lo` bigint unsigned NOT NULL COMMENT 'Lower 64 bits of dHash',
  `phash_hex` char(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '64-bit pHash as 16 hex characters',
  `phash_int` bigint unsigned DEFAULT NULL COMMENT '64-bit pHash as integer',
  `algorithm_version` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1.0',
  `computed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_file` (`file_registry_id`),
  KEY `file_registry_perceptual_hashes_dhash_int_hi_index` (`dhash_int_hi`),
  KEY `file_registry_perceptual_hashes_dhash_int_lo_index` (`dhash_int_lo`),
  KEY `file_registry_perceptual_hashes_phash_int_index` (`phash_int`),
  CONSTRAINT `file_registry_perceptual_hashes_file_registry_id_foreign` FOREIGN KEY (`file_registry_id`) REFERENCES `file_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_similar_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_similar_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `file_id_a` bigint unsigned NOT NULL,
  `file_id_b` bigint unsigned NOT NULL,
  `hamming_distance` tinyint unsigned NOT NULL,
  `similarity_type` enum('exact','near_duplicate','similar') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `algorithm_used` enum('dhash','phash','combined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dhash',
  `status` enum('pending_review','confirmed_duplicate','false_positive','different_versions') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_review',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pair` (`file_id_a`,`file_id_b`),
  KEY `file_registry_similar_images_file_id_b_index` (`file_id_b`),
  KEY `file_registry_similar_images_status_index` (`status`),
  KEY `file_registry_similar_images_hamming_distance_index` (`hamming_distance`),
  CONSTRAINT `file_registry_similar_images_file_id_a_foreign` FOREIGN KEY (`file_id_a`) REFERENCES `file_registry` (`id`) ON DELETE CASCADE,
  CONSTRAINT `file_registry_similar_images_file_id_b_foreign` FOREIGN KEY (`file_id_b`) REFERENCES `file_registry` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_ordered_pair` CHECK ((`file_id_a` < `file_id_b`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_similar_videos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_similar_videos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `video_hash_id_1` bigint unsigned NOT NULL,
  `video_hash_id_2` bigint unsigned NOT NULL,
  `similarity_score` decimal(5,4) NOT NULL COMMENT '0.0000 to 1.0000',
  `matched_keyframes` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Number of keyframes with similarity > threshold',
  `avg_hamming_distance` tinyint unsigned DEFAULT NULL COMMENT 'Average hamming distance across matched frames',
  `status` enum('pending_review','confirmed_duplicate','false_positive','different_versions') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_review',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_video_pair` (`video_hash_id_1`,`video_hash_id_2`),
  KEY `file_registry_similar_videos_video_hash_id_2_index` (`video_hash_id_2`),
  KEY `file_registry_similar_videos_similarity_score_index` (`similarity_score`),
  KEY `file_registry_similar_videos_status_index` (`status`),
  CONSTRAINT `file_registry_similar_videos_video_hash_id_1_foreign` FOREIGN KEY (`video_hash_id_1`) REFERENCES `file_registry_video_hashes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `file_registry_similar_videos_video_hash_id_2_foreign` FOREIGN KEY (`video_hash_id_2`) REFERENCES `file_registry_video_hashes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_video_ordered_pair` CHECK ((`video_hash_id_1` < `video_hash_id_2`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_sync_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_type` enum('initial_import','verification','reorganization','nextcloud_sync','bundle_scan','catalog_sync') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('running','completed','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'running',
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `heartbeat_at` timestamp NULL DEFAULT NULL COMMENT 'Last heartbeat from running process (updated every 20 min)',
  `completed_at` timestamp NULL DEFAULT NULL,
  `files_scanned` int DEFAULT '0',
  `files_registered` int DEFAULT '0',
  `files_updated` int DEFAULT '0',
  `files_orphaned` int DEFAULT '0',
  `duplicates_found` int DEFAULT '0',
  `errors` int DEFAULT '0',
  `scope_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Path scope for this run',
  `error_log` json DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `scan_results` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_run_type` (`run_type`),
  KEY `idx_status` (`status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `file_registry_id` bigint unsigned NOT NULL,
  `tag` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` enum('ai','manual','exif','import') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ai',
  `confidence` decimal(3,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_frt_file_tag_source` (`file_registry_id`,`tag`,`source`),
  KEY `idx_frt_source` (`source`),
  KEY `idx_frt_tag` (`tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_registry_video_hashes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_registry_video_hashes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `file_registry_id` bigint unsigned NOT NULL,
  `duration_seconds` int unsigned DEFAULT NULL COMMENT 'Video duration in seconds',
  `keyframe_count` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Number of extracted keyframes',
  `keyframe_hashes` json DEFAULT NULL COMMENT 'Array of {timestamp, phash, dhash} for each keyframe',
  `combined_hash` char(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Aggregated video fingerprint',
  `hash_algorithm` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'phash' COMMENT 'Primary algorithm: phash, dhash, or combined',
  `extraction_method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'interval' COMMENT 'interval, scene_change, or keyframe',
  `extraction_interval` tinyint unsigned NOT NULL DEFAULT '10' COMMENT 'Seconds between frames if interval method',
  `width` smallint unsigned DEFAULT NULL,
  `height` smallint unsigned DEFAULT NULL,
  `codec` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fps` decimal(6,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_video_file` (`file_registry_id`),
  KEY `file_registry_video_hashes_combined_hash_index` (`combined_hash`),
  KEY `file_registry_video_hashes_duration_seconds_index` (`duration_seconds`),
  KEY `file_registry_video_hashes_keyframe_count_index` (`keyframe_count`),
  CONSTRAINT `file_registry_video_hashes_file_registry_id_foreign` FOREIGN KEY (`file_registry_id`) REFERENCES `file_registry` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_versions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `file_registry_id` int unsigned NOT NULL,
  `version_number` int unsigned NOT NULL DEFAULT '1',
  `nextcloud_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned DEFAULT NULL,
  `content_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `change_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_versions_number` (`file_registry_id`,`version_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `folder_research_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `folder_research_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `folder_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Path where this unknown folder was encountered',
  `file_count` int unsigned DEFAULT '0',
  `total_size_bytes` bigint unsigned DEFAULT '0',
  `status` enum('pending','researching','completed','failed','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `research_result` json DEFAULT NULL COMMENT 'Web research findings',
  `suggested_meaning` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suggested_category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confidence` tinyint unsigned DEFAULT NULL COMMENT 'Research confidence 0-100',
  `queued_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `researched_at` timestamp NULL DEFAULT NULL,
  `research_source` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Which source provided the answer: rag, llm, web',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `folder_name_lower` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (lower(`folder_name`)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_folder_pending` (`folder_name_lower`,`status`),
  KEY `idx_status` (`status`),
  KEY `idx_queued_at` (`queued_at`),
  KEY `idx_folder_name_lower` (`folder_name_lower`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `folder_semantics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `folder_semantics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `folder_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Folder name, e.g., GTA5',
  `folder_name_lower` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (lower(`folder_name`)) STORED,
  `path_pattern` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional path pattern, e.g., */GTA5/*, */Desktop/GUIDE POST LINKS/*',
  `semantic_meaning` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'What this folder represents, e.g., Grand Theft Auto 5 video game',
  `semantic_category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'High-level category: gaming, software, reference, work, etc.',
  `suggested_destination` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Learned destination path when Human corrects AI',
  `source` enum('human','ai_suggested','rag_inferred','llm_interpreted','web_research') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'human',
  `confidence` tinyint unsigned DEFAULT '100' COMMENT '0-100, decreases if Human overrides',
  `times_used` int unsigned DEFAULT '0' COMMENT 'How often this semantic was applied',
  `times_overridden` int unsigned DEFAULT '0' COMMENT 'How often Human changed AI suggestion using this',
  `learned_from_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Original path where this was learned',
  `learned_from_action_id` bigint unsigned DEFAULT NULL COMMENT 'FK to windows_file_actions if learned from correction',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_folder_pattern` (`folder_name_lower`,`path_pattern`(100)),
  KEY `idx_folder_name_lower` (`folder_name_lower`),
  KEY `idx_semantic_category` (`semantic_category`),
  KEY `idx_path_pattern` (`path_pattern`(100)),
  KEY `idx_source` (`source`),
  KEY `idx_confidence` (`confidence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_activity_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` int unsigned DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_activity_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_ancestor_paths`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_ancestor_paths` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `root_person_id` int unsigned NOT NULL COMMENT 'Tree owner / starting person',
  `ancestor_id` int unsigned NOT NULL COMMENT 'The ancestor at the end of this path',
  `generation` smallint unsigned NOT NULL COMMENT '0=root, 1=parent, 2=grandparent, etc.',
  `path_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON array of person IDs from root to ancestor',
  `bloodline_tier` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '1=direct ancestor, 2=sibling/child of direct, 3=collateral',
  `rebuilt_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tree_root_ancestor` (`tree_id`,`root_person_id`,`ancestor_id`),
  KEY `idx_tree_ancestor` (`tree_id`,`ancestor_id`),
  KEY `idx_tier_gen` (`tree_id`,`bloodline_tier`,`generation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_change_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_change_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL COMMENT 'Tree this change belongs to',
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'person, family, event, residence, source, media, etc.',
  `entity_id` int unsigned NOT NULL COMMENT 'ID of the entity that was changed',
  `action` enum('create','update','delete') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Field that was changed (NULL for create/delete)',
  `old_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Previous value (NULL for create)',
  `new_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'New value (NULL for delete)',
  `old_data` json DEFAULT NULL COMMENT 'Full entity snapshot before change (for delete/complex changes)',
  `new_data` json DEFAULT NULL COMMENT 'Full entity snapshot after change (for create/complex changes)',
  `changed_by` int unsigned DEFAULT NULL COMMENT 'User ID who made the change',
  `changed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `change_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional reason/note for the change',
  `batch_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'UUID to group related changes (e.g., GEDCOM import)',
  PRIMARY KEY (`id`),
  KEY `idx_tree_id` (`tree_id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_changed_at` (`changed_at`),
  KEY `idx_changed_by` (`changed_by`),
  KEY `idx_batch_id` (`batch_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity_history` (`entity_type`,`entity_id`,`changed_at` DESC),
  CONSTRAINT `fk_change_history_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_children`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_children` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `family_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL,
  `father_relationship` enum('Natural','Adopted','Step','Foster','Unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Natural',
  `mother_relationship` enum('Natural','Adopted','Step','Foster','Unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Natural',
  `birth_order` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_family_person` (`family_id`,`person_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_family_birth_order` (`family_id`,`birth_order`),
  CONSTRAINT `fk_child_family` FOREIGN KEY (`family_id`) REFERENCES `genealogy_families` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_child_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_citations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_citations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_id` int unsigned NOT NULL,
  `person_id` int unsigned DEFAULT NULL,
  `family_id` int unsigned DEFAULT NULL,
  `media_id` int unsigned DEFAULT NULL,
  `fact_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quality` tinyint DEFAULT NULL,
  `evidence_type` enum('direct','indirect','negative') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'GPS evidence type: direct=explicitly states fact, indirect=requires inference, negative=absence proves something',
  `information_type` enum('primary','secondary','indeterminate') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Information category: primary=from participant/eyewitness, secondary=from derivative account',
  `evidence_analysis` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'GPS analysis notes explaining how evidence supports or contradicts conclusions',
  `conclusion_id` int unsigned DEFAULT NULL COMMENT 'Links this evidence to a specific research conclusion',
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_id`),
  KEY `idx_family` (`family_id`),
  KEY `fk_citation_media` (`media_id`),
  KEY `idx_conclusion_id` (`conclusion_id`),
  KEY `idx_person_fact_type` (`person_id`,`fact_type`),
  CONSTRAINT `fk_citation_family` FOREIGN KEY (`family_id`) REFERENCES `genealogy_families` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_citation_media` FOREIGN KEY (`media_id`) REFERENCES `genealogy_media` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_citation_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_citation_source` FOREIGN KEY (`source_id`) REFERENCES `genealogy_sources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_citations_conclusion` FOREIGN KEY (`conclusion_id`) REFERENCES `genealogy_evidence_conclusions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_dna_kits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_dna_kits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL COMMENT 'FK to genealogy_persons',
  `kit_provider` enum('ancestry','23andme','ftdna','myheritage','gedmatch','livingdna','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'DNA testing company',
  `kit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Provider-specific kit identifier',
  `raw_data_file` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path to uploaded raw DNA data file',
  `haplogroup_maternal` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'mtDNA haplogroup',
  `haplogroup_paternal` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Y-DNA haplogroup (males only)',
  `ethnicity_estimate` json DEFAULT NULL COMMENT 'Ethnicity/ancestry breakdown from provider',
  `total_cm_shared` decimal(10,2) DEFAULT NULL COMMENT 'Total cM in kit for reference',
  `uploaded_at` timestamp NULL DEFAULT NULL COMMENT 'When raw data was uploaded',
  `last_match_sync` timestamp NULL DEFAULT NULL COMMENT 'Last time matches were synced from provider',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_person_provider` (`person_id`,`kit_provider`),
  KEY `idx_kit_provider` (`kit_provider`),
  KEY `idx_kit_id` (`kit_id`),
  CONSTRAINT `fk_dna_kits_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_dna_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_dna_matches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kit_id` int unsigned NOT NULL COMMENT 'FK to genealogy_dna_kits',
  `match_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name of the DNA match',
  `match_kit_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Match kit ID if known (for cross-referencing)',
  `match_provider_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Provider-specific match identifier',
  `shared_cm` decimal(8,2) NOT NULL COMMENT 'Total shared centiMorgans',
  `shared_segments` int unsigned NOT NULL DEFAULT '0' COMMENT 'Number of shared DNA segments',
  `longest_segment_cm` decimal(8,2) DEFAULT NULL COMMENT 'Longest shared segment in cM',
  `predicted_relationship` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'AI/algorithm predicted relationship',
  `confidence_score` decimal(5,2) DEFAULT NULL COMMENT 'Prediction confidence 0-100',
  `confirmed_relationship` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'User-confirmed actual relationship',
  `common_ancestor_id` int unsigned DEFAULT NULL COMMENT 'FK to genealogy_persons if identified',
  `maternal_side` tinyint(1) DEFAULT NULL COMMENT '1=maternal, 0=paternal, NULL=unknown',
  `match_tree_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL to match family tree if available',
  `match_tree_size` int unsigned DEFAULT NULL COMMENT 'Number of people in match tree',
  `shared_ancestor_hints` json DEFAULT NULL COMMENT 'Potential shared ancestors from provider',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `match_date` date DEFAULT NULL COMMENT 'When match was first identified',
  `last_updated` timestamp NULL DEFAULT NULL COMMENT 'Last sync from provider',
  `is_starred` tinyint(1) DEFAULT '0' COMMENT 'User marked as important',
  `is_hidden` tinyint(1) DEFAULT '0' COMMENT 'User chose to hide match',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_kit_match` (`kit_id`,`match_provider_id`),
  KEY `idx_shared_cm` (`shared_cm` DESC),
  KEY `idx_predicted_relationship` (`predicted_relationship`),
  KEY `idx_confirmed_relationship` (`confirmed_relationship`),
  KEY `idx_common_ancestor` (`common_ancestor_id`),
  KEY `idx_match_provider_id` (`match_provider_id`),
  KEY `idx_starred` (`is_starred`),
  CONSTRAINT `fk_dna_matches_ancestor` FOREIGN KEY (`common_ancestor_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dna_matches_kit` FOREIGN KEY (`kit_id`) REFERENCES `genealogy_dna_kits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_dna_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_dna_segments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `match_id` int unsigned NOT NULL COMMENT 'FK to genealogy_dna_matches',
  `chromosome` tinyint unsigned NOT NULL COMMENT 'Chromosome number: 1-22, X=23',
  `start_position` bigint unsigned NOT NULL COMMENT 'Segment start position in base pairs',
  `end_position` bigint unsigned NOT NULL COMMENT 'Segment end position in base pairs',
  `cm_length` decimal(8,2) NOT NULL COMMENT 'Segment length in centiMorgans',
  `snp_count` int unsigned DEFAULT NULL COMMENT 'Number of SNPs in segment',
  `is_full_ibd` tinyint(1) DEFAULT NULL COMMENT 'Full IBD vs half IBD if known',
  `side` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'maternal, paternal, or unknown',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_match_id` (`match_id`),
  KEY `idx_position` (`chromosome`,`start_position`,`end_position`),
  KEY `idx_cm_length` (`cm_length` DESC),
  CONSTRAINT `fk_dna_segments_match` FOREIGN KEY (`match_id`) REFERENCES `genealogy_dna_matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_dna_triangulation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_dna_triangulation` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kit_id` int unsigned NOT NULL COMMENT 'FK to source kit for triangulation',
  `match_id_1` int unsigned NOT NULL COMMENT 'First match in triangulation',
  `match_id_2` int unsigned NOT NULL COMMENT 'Second match in triangulation',
  `match_id_3` int unsigned DEFAULT NULL COMMENT 'Optional third match (can extend groups)',
  `chromosome` tinyint unsigned NOT NULL COMMENT 'Chromosome number: 1-22, X=23',
  `overlap_start` bigint unsigned NOT NULL COMMENT 'Overlapping segment start position',
  `overlap_end` bigint unsigned NOT NULL COMMENT 'Overlapping segment end position',
  `overlap_cm` decimal(8,2) DEFAULT NULL COMMENT 'Overlapping segment length in cM',
  `common_ancestor_id` int unsigned DEFAULT NULL COMMENT 'FK to genealogy_persons if identified',
  `confidence` enum('high','medium','low') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'medium' COMMENT 'Triangulation confidence level',
  `verification_status` enum('unverified','verified','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'unverified',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_triangulation` (`kit_id`,`match_id_1`,`match_id_2`,`chromosome`,`overlap_start`),
  KEY `idx_match_1` (`match_id_1`),
  KEY `idx_match_2` (`match_id_2`),
  KEY `idx_match_3` (`match_id_3`),
  KEY `idx_chromosome` (`chromosome`),
  KEY `idx_common_ancestor` (`common_ancestor_id`),
  KEY `idx_confidence` (`confidence`),
  CONSTRAINT `fk_triangulation_ancestor` FOREIGN KEY (`common_ancestor_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_triangulation_kit` FOREIGN KEY (`kit_id`) REFERENCES `genealogy_dna_kits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_triangulation_match1` FOREIGN KEY (`match_id_1`) REFERENCES `genealogy_dna_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_triangulation_match2` FOREIGN KEY (`match_id_2`) REFERENCES `genealogy_dna_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_triangulation_match3` FOREIGN KEY (`match_id_3`) REFERENCES `genealogy_dna_matches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_dna_triangulation_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_dna_triangulation_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kit_id` int unsigned NOT NULL,
  `group_number` int NOT NULL,
  `match_count` int NOT NULL,
  `match_hash` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_ids` json NOT NULL,
  `group_data` json DEFAULT NULL,
  `avg_shared_cm` decimal(8,2) DEFAULT NULL,
  `chromosome_count` int DEFAULT NULL,
  `cohesion_percent` decimal(5,2) DEFAULT NULL,
  `estimated_relationship` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_kit_hash` (`kit_id`,`match_hash`),
  KEY `idx_relationship` (`estimated_relationship`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_duplicate_pairs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_duplicate_pairs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person1_id` int unsigned NOT NULL,
  `person2_id` int unsigned NOT NULL,
  `score` decimal(4,3) DEFAULT '0.000',
  `status` enum('pending','pending_merge','merged','rejected','resolved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `merged_into_id` int unsigned DEFAULT NULL COMMENT 'If merged, which person was kept',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `resolved_by` int unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair` (`tree_id`,`person1_id`,`person2_id`),
  KEY `idx_person1` (`person1_id`),
  KEY `idx_person2` (`person2_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_dup_person1` FOREIGN KEY (`person1_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dup_person2` FOREIGN KEY (`person2_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dup_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_place` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `place_id` int unsigned DEFAULT NULL,
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`event_type`),
  KEY `fk_event_source` (`source_id`),
  KEY `idx_person_type_date` (`person_id`,`event_type`,`event_date`(10)),
  KEY `idx_place_id` (`place_id`),
  CONSTRAINT `fk_event_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_place` FOREIGN KEY (`place_id`) REFERENCES `genealogy_places` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_event_source` FOREIGN KEY (`source_id`) REFERENCES `genealogy_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_evidence_conclusions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_evidence_conclusions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned DEFAULT NULL,
  `family_id` int unsigned DEFAULT NULL,
  `fact_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'GEDCOM fact type (BIRT, DEAT, MARR, etc.)',
  `conclusion_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The concluded fact statement',
  `confidence_level` enum('proven','probable','possible','speculative') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'possible',
  `reasoning` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Explanation of how evidence supports this conclusion',
  `conflicting_evidence` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Notes on any conflicting evidence considered',
  `gps_compliant` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether this conclusion meets GPS standards',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree_id` (`tree_id`),
  KEY `idx_person_id` (`person_id`),
  KEY `idx_family_id` (`family_id`),
  KEY `idx_fact_type` (`fact_type`),
  CONSTRAINT `genealogy_evidence_conclusions_ibfk_1` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `genealogy_evidence_conclusions_ibfk_2` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `genealogy_evidence_conclusions_ibfk_3` FOREIGN KEY (`family_id`) REFERENCES `genealogy_families` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='GPS evidence conclusions - tracks fact conclusions derived from analyzed evidence';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_external_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_external_connections` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `service_type` enum('familysearch','ancestry','findmypast','myheritage','geneanet','wikitree','findagrave','nara') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `refresh_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `status` enum('active','expired','revoked','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `sync_errors` int DEFAULT '0',
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tree_service` (`tree_id`,`service_type`),
  KEY `idx_user` (`user_id`),
  KEY `idx_service` (`service_type`),
  CONSTRAINT `fk_conn_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_external_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_external_ids` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `record_type` enum('person','family','source','media','repository','place') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int unsigned NOT NULL,
  `external_id` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_type` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URI identifying the authority',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exid_record` (`record_type`,`record_id`),
  KEY `idx_exid_external` (`external_id`(100)),
  KEY `idx_exid_type` (`id_type`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_external_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_external_records` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned DEFAULT NULL,
  `service_type` enum('familysearch','ancestry','findmypast','myheritage','geneanet','wikitree','findagrave','nara') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `record_data` json NOT NULL,
  `match_confidence` decimal(3,2) DEFAULT '0.50',
  `status` enum('pending','matched','rejected','imported') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `matched_at` timestamp NULL DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_service_record` (`service_type`,`external_id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_ext_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ext_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_external_service_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_external_service_registry` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `service_type` varchar(50) NOT NULL,
  `field_alias` varchar(100) DEFAULT NULL,
  `url_pattern` varchar(255) DEFAULT NULL,
  `display_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_field_alias` (`field_alias`),
  KEY `idx_service_type` (`service_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_external_syncs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_external_syncs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `connection_id` int unsigned NOT NULL,
  `sync_type` enum('full','incremental','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('import','export','bidirectional') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'import',
  `status` enum('pending','running','completed','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `records_found` int DEFAULT '0',
  `records_imported` int DEFAULT '0',
  `records_updated` int DEFAULT '0',
  `records_skipped` int DEFAULT '0',
  `records_failed` int DEFAULT '0',
  `error_log` json DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_connection` (`connection_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_sync_conn` FOREIGN KEY (`connection_id`) REFERENCES `genealogy_external_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_face_match_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_face_match_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `media_id` int unsigned NOT NULL,
  `face_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name from photo XMP metadata',
  `suggested_person_id` int unsigned DEFAULT NULL,
  `match_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'nickname, typo, soundex, levenshtein',
  `confidence_score` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '0-100 confidence score',
  `face_region` json DEFAULT NULL COMMENT 'Face region coordinates {x,y,w,h}',
  `match_details` json DEFAULT NULL COMMENT 'Details about why match was suggested',
  `status` enum('pending','approved','rejected','auto_linked','ignored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `file_registry_face_id` bigint unsigned DEFAULT NULL COMMENT 'Links to file_registry_faces',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_media_face` (`media_id`,`face_name`),
  KEY `genealogy_face_match_queue_suggested_person_id_index` (`suggested_person_id`),
  KEY `genealogy_face_match_queue_status_index` (`status`),
  KEY `genealogy_face_match_queue_tree_id_status_index` (`tree_id`,`status`),
  KEY `idx_queue_face_id` (`file_registry_face_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_families`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_families` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `gedcom_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `husband_id` int unsigned DEFAULT NULL,
  `wife_id` int unsigned DEFAULT NULL,
  `marriage_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marriage_place` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `marriage_lat` decimal(10,6) DEFAULT NULL,
  `marriage_lon` decimal(10,6) DEFAULT NULL,
  `divorce_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `divorce_place` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `annulment_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tree_gedcom` (`tree_id`,`gedcom_id`),
  KEY `idx_husband` (`husband_id`),
  KEY `idx_wife` (`wife_id`),
  KEY `idx_tree_marriage` (`tree_id`,`marriage_date`(10)),
  KEY `idx_families_uid` (`uid`),
  CONSTRAINT `fk_family_husband` FOREIGN KEY (`husband_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_family_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_family_wife` FOREIGN KEY (`wife_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_family_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_family_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `family_id` int unsigned NOT NULL,
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_place` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `place_id` int unsigned DEFAULT NULL,
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `genealogy_family_events_family_id_foreign` (`family_id`),
  KEY `genealogy_family_events_source_id_foreign` (`source_id`),
  KEY `genealogy_family_events_event_type_index` (`event_type`),
  KEY `idx_family_event_place_id` (`place_id`),
  CONSTRAINT `fk_family_event_place` FOREIGN KEY (`place_id`) REFERENCES `genealogy_places` (`id`) ON DELETE SET NULL,
  CONSTRAINT `genealogy_family_events_family_id_foreign` FOREIGN KEY (`family_id`) REFERENCES `genealogy_families` (`id`) ON DELETE CASCADE,
  CONSTRAINT `genealogy_family_events_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `genealogy_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_family_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_family_media` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `family_id` int unsigned NOT NULL,
  `media_id` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_family_media` (`family_id`,`media_id`),
  KEY `idx_media` (`media_id`),
  CONSTRAINT `fk_fm_family` FOREIGN KEY (`family_id`) REFERENCES `genealogy_families` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fm_media` FOREIGN KEY (`media_id`) REFERENCES `genealogy_media` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_family_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_family_sources` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `family_id` int unsigned NOT NULL,
  `source_id` int unsigned NOT NULL,
  `page` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quality` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_family_source` (`family_id`,`source_id`),
  KEY `idx_source` (`source_id`),
  CONSTRAINT `fk_fs_family` FOREIGN KEY (`family_id`) REFERENCES `genealogy_families` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fs_source` FOREIGN KEY (`source_id`) REFERENCES `genealogy_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_fan_clusters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_fan_clusters` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL COMMENT 'FK to genealogy_persons - the research subject',
  `cluster_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Descriptive name for this cluster',
  `research_period` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Time period e.g. 1850-1880',
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Geographic focus of cluster',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Research notes and methodology',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_person_id` (`person_id`),
  KEY `idx_research_period` (`research_period`),
  KEY `idx_location` (`location`(100)),
  CONSTRAINT `fk_fan_cluster_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_fan_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_fan_members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cluster_id` int unsigned NOT NULL COMMENT 'FK to genealogy_fan_clusters',
  `member_person_id` int unsigned DEFAULT NULL COMMENT 'FK to genealogy_persons if linked',
  `member_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name as appears in source (for unlinked persons)',
  `relationship_type` enum('friend','associate','neighbor','witness','business','church','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `source_record_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'census, marriage, deed, probate, church, etc.',
  `source_citation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Full citation for this connection',
  `interaction_date` date DEFAULT NULL COMMENT 'Date of documented interaction',
  `interaction_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Description of the interaction/connection',
  `confidence` enum('high','medium','low') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_member_person_id` (`member_person_id`),
  KEY `idx_relationship_type` (`relationship_type`),
  KEY `idx_source_record_type` (`source_record_type`),
  KEY `idx_interaction_date` (`interaction_date`),
  KEY `idx_confidence` (`confidence`),
  KEY `idx_cluster_relationship` (`cluster_id`,`relationship_type`),
  KEY `idx_member_name` (`member_name`(100)),
  CONSTRAINT `fk_fan_member_cluster` FOREIGN KEY (`cluster_id`) REFERENCES `genealogy_fan_clusters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fan_member_person` FOREIGN KEY (`member_person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_historical_boundaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_historical_boundaries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `census_year` int NOT NULL,
  `country` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'US',
  `state_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `boundary_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `boundary_type` enum('state','county','township','city') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `boundary_level` int DEFAULT '1',
  `geojson_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_year_country` (`census_year`,`country`),
  KEY `idx_state` (`state_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_historical_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_historical_maps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `map_year` int DEFAULT NULL,
  `source` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tile_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bounds` json DEFAULT NULL,
  `center_latitude` decimal(10,6) DEFAULT NULL,
  `center_longitude` decimal(10,6) DEFAULT NULL,
  `attribution` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_year` (`map_year`),
  KEY `idx_location` (`center_latitude`,`center_longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_intake_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_intake_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tree_id` int unsigned NOT NULL,
  `root_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `packet_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staged',
  `staged_snapshot` json NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_genealogy_intake_runs_run_key` (`run_key`),
  KEY `idx_genealogy_intake_runs_tree_status` (`tree_id`,`status`),
  KEY `idx_genealogy_intake_runs_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_media` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `gedcom_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `nextcloud_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `local_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_format` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int unsigned DEFAULT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `media_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `transcription_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ai_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `subject_tags` json DEFAULT NULL,
  `exif_data` json DEFAULT NULL,
  `date_taken` timestamp NULL DEFAULT NULL,
  `gps_latitude` decimal(10,7) DEFAULT NULL,
  `gps_longitude` decimal(10,7) DEFAULT NULL,
  `camera_make` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `camera_model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `analysis_status` enum('pending','processing','completed','failed','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `enrichment_status` enum('pending','processing','completed','failed','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  `enrichment_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `enriched_at` timestamp NULL DEFAULT NULL,
  `face_sync_status` enum('pending','synced','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Status of face region XMP write-back',
  `face_sync_at` timestamp NULL DEFAULT NULL COMMENT 'Last successful face region sync',
  `face_sync_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Error from last failed face sync attempt',
  `analyzed_at` timestamp NULL DEFAULT NULL,
  `analysis_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_folder` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcription` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `transcription_source` enum('manual','ocr','ai') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcription_date` timestamp NULL DEFAULT NULL,
  `media_type` enum('photo','document','certificate','census','military','obituary','headstone','video','audio','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'photo',
  `file_exists` tinyint(1) DEFAULT '0',
  `imported_at` timestamp NULL DEFAULT NULL,
  `width` int unsigned DEFAULT NULL,
  `height` int unsigned DEFAULT NULL,
  `has_faces` tinyint(1) DEFAULT '0',
  `privacy` enum('private','shared','public') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_sensitive` tinyint(1) DEFAULT '0',
  `face_count` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_face_sync_at` timestamp NULL DEFAULT NULL COMMENT 'When faces were last synced from file_registry',
  PRIMARY KEY (`id`),
  KEY `idx_tree_gedcom` (`tree_id`,`gedcom_id`),
  KEY `idx_title` (`title`(100)),
  KEY `idx_media_type` (`media_type`),
  KEY `idx_tree_file_exists` (`tree_id`,`file_exists`),
  KEY `idx_tree_has_faces` (`tree_id`,`has_faces`),
  KEY `idx_analysis_queue` (`analysis_status`,`file_exists`),
  KEY `idx_media_face_sync` (`tree_id`,`face_sync_status`),
  KEY `idx_genealogy_media_nextcloud_path` (`nextcloud_path`),
  KEY `idx_media_uid` (`uid`),
  KEY `idx_genealogy_media_enrichment` (`enrichment_status`,`media_type`,`file_exists`),
  CONSTRAINT `fk_media_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_media_crops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_media_crops` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `media_id` int unsigned NOT NULL,
  `crop_top` int DEFAULT NULL,
  `crop_left` int DEFAULT NULL,
  `crop_width` int DEFAULT NULL,
  `crop_height` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_crops_media` (`media_id`),
  CONSTRAINT `fk_media_crops_media` FOREIGN KEY (`media_id`) REFERENCES `genealogy_media` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_media_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_media_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `media_id` int unsigned NOT NULL,
  `file_path` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `media_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'GEDCOM TYPE value',
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_files_media` (`media_id`),
  CONSTRAINT `fk_media_files_media` FOREIGN KEY (`media_id`) REFERENCES `genealogy_media` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_media_scan_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_media_scan_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `nextcloud_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scanned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `has_faces` tinyint(1) NOT NULL DEFAULT '0',
  `face_count` int NOT NULL DEFAULT '0',
  `face_names` json DEFAULT NULL,
  `file_size` int unsigned DEFAULT NULL,
  `scan_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_tree_path_unique` (`tree_id`,`nextcloud_path`(255)),
  KEY `idx_scanned_at` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_name_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_name_translations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `language` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `translated_name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `given_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `surname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name_trans_person` (`person_id`),
  CONSTRAINT `fk_name_trans_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_name_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_name_variants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `name_type` enum('birth','married','maiden','alias','nickname','religious','phonetic') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `given_names` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `surname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` int unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name_variants_person` (`person_id`),
  KEY `idx_name_variants_type` (`name_type`),
  KEY `idx_name_variants_surname` (`surname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_name_variations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_name_variations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_type` enum('given','surname') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `variation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_origin` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_ai_generated` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name_variation` (`tree_id`,`original_name`,`name_type`,`variation`),
  KEY `idx_original` (`original_name`),
  KEY `idx_variation` (`variation`),
  CONSTRAINT `fk_variation_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_newspaper_clippings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_newspaper_clippings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `source_id` int unsigned DEFAULT NULL COMMENT 'FK to genealogy_sources if linked',
  `newspaper_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `newspaper_lccn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Library of Congress Control Number',
  `publication_date` date DEFAULT NULL,
  `page_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `edition` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `headline` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `clipping_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `clipping_type` enum('obituary','birth','marriage','death','military','social','legal','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'other',
  `ocr_confidence` decimal(3,2) DEFAULT NULL COMMENT '0.00 to 1.00',
  `api_source` enum('loc','nara','europeana','familysearch','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'manual',
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ID in external system',
  `original_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `local_image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_extracted_names` json DEFAULT NULL COMMENT 'Array of {name, role, confidence}',
  `ai_extracted_dates` json DEFAULT NULL COMMENT 'Array of {date, type, confidence}',
  `ai_extracted_places` json DEFAULT NULL COMMENT 'Array of {place, type, confidence}',
  `ai_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'AI-generated summary',
  `ai_confidence` decimal(3,2) DEFAULT NULL,
  `ai_processed_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_verified` tinyint(1) DEFAULT '0',
  `verified_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_date` (`publication_date`),
  KEY `idx_type` (`clipping_type`),
  KEY `idx_lccn` (`newspaper_lccn`),
  KEY `idx_external` (`api_source`,`external_id`),
  KEY `fk_clipping_source` (`source_id`),
  FULLTEXT KEY `idx_text` (`clipping_text`,`headline`,`newspaper_name`),
  CONSTRAINT `fk_clipping_source` FOREIGN KEY (`source_id`) REFERENCES `genealogy_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_clipping_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_person_clippings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_person_clippings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `clipping_id` int unsigned NOT NULL,
  `relevance_type` enum('subject','mentioned','relative','witness','author','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'mentioned',
  `relationship_note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g., "husband of deceased"',
  `confidence` decimal(3,2) DEFAULT NULL COMMENT '0.00 to 1.00',
  `match_method` enum('ai_auto','ai_suggested','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'manual',
  `is_verified` tinyint(1) DEFAULT '0',
  `verified_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_clipping` (`person_id`,`clipping_id`),
  KEY `idx_clipping` (`clipping_id`),
  KEY `idx_relevance` (`relevance_type`),
  KEY `idx_verified` (`is_verified`),
  CONSTRAINT `fk_pc_clipping` FOREIGN KEY (`clipping_id`) REFERENCES `genealogy_newspaper_clippings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pc_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_person_coverage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_person_coverage` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL,
  `bloodline_tier` tinyint unsigned NOT NULL DEFAULT '3' COMMENT '1=direct, 2=sibling of direct, 3=collateral, 4=married-in only',
  `generation_distance` smallint unsigned DEFAULT NULL COMMENT 'Hops from root, NULL if not on bloodline',
  `data_gap_score` decimal(4,3) NOT NULL DEFAULT '0.000' COMMENT '0.0=complete, 1.0=all key fields missing',
  `research_exhaustion_score` decimal(4,3) NOT NULL DEFAULT '0.000' COMMENT '0.0=never searched, 1.0=all repos tried with no results',
  `pending_hint_count` smallint unsigned NOT NULL DEFAULT '0',
  `last_searched_at` timestamp NULL DEFAULT NULL,
  `search_count_30d` smallint unsigned NOT NULL DEFAULT '0',
  `negative_count_30d` smallint unsigned NOT NULL DEFAULT '0',
  `priority_score` decimal(6,4) NOT NULL DEFAULT '0.0000' COMMENT 'Composite: tier×0.40 + gap×0.35 + staleness×0.25',
  `priority_rank` int unsigned DEFAULT NULL COMMENT 'Rank within tree (1=highest priority)',
  `coverage_updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tree_person` (`tree_id`,`person_id`),
  KEY `idx_tree_priority` (`tree_id`,`priority_score` DESC),
  KEY `idx_tree_tier` (`tree_id`,`bloodline_tier`,`priority_score` DESC),
  KEY `idx_last_searched` (`tree_id`,`last_searched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_person_external_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_person_external_links` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `service_type` enum('familysearch','ancestry','findmypast','myheritage','geneanet','wikitree','findagrave','nara') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_person_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `link_type` enum('confirmed','suggested','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'confirmed',
  `sync_enabled` tinyint(1) DEFAULT '1',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_service` (`person_id`,`service_type`),
  KEY `idx_external` (`service_type`,`external_person_id`),
  CONSTRAINT `fk_link_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_person_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_person_media` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `media_id` int unsigned NOT NULL,
  `is_primary` tinyint(1) DEFAULT '0',
  `face_region_x` decimal(5,4) DEFAULT NULL,
  `face_region_y` decimal(5,4) DEFAULT NULL,
  `face_region_w` decimal(5,4) DEFAULT NULL,
  `face_region_h` decimal(5,4) DEFAULT NULL,
  `face_confirmed` tinyint(1) DEFAULT '0',
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_media` (`person_id`,`media_id`),
  KEY `idx_media` (`media_id`),
  KEY `idx_person_primary` (`person_id`,`is_primary`),
  KEY `idx_genealogy_person_media_face_confirmed` (`face_confirmed`),
  CONSTRAINT `fk_pm_media` FOREIGN KEY (`media_id`) REFERENCES `genealogy_media` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_person_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_person_sources` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `source_id` int unsigned NOT NULL,
  `page` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quality` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_source` (`person_id`,`source_id`),
  KEY `idx_source` (`source_id`),
  CONSTRAINT `fk_ps_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ps_source` FOREIGN KEY (`source_id`) REFERENCES `genealogy_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_persons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_persons` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `gedcom_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `given_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `surname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suffix` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nickname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sex` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_place` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `birth_lat` decimal(10,6) DEFAULT NULL,
  `birth_lon` decimal(10,6) DEFAULT NULL,
  `birth_place_id` int unsigned DEFAULT NULL,
  `death_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `death_place` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `death_lat` decimal(10,6) DEFAULT NULL,
  `death_lon` decimal(10,6) DEFAULT NULL,
  `death_place_id` int unsigned DEFAULT NULL,
  `burial_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `burial_place` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `burial_lat` decimal(10,6) DEFAULT NULL,
  `burial_lon` decimal(10,6) DEFAULT NULL,
  `burial_place_id` int unsigned DEFAULT NULL,
  `occupation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `education` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `religion` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `primary_photo_id` int unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `primary_language` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `living` tinyint(1) DEFAULT NULL,
  `privacy_override` enum('default','public','private') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'default',
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `physical_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `nationality` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ssn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cause_of_death` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tree_gedcom` (`tree_id`,`gedcom_id`),
  KEY `idx_given_name` (`given_name`),
  KEY `idx_birth_date` (`birth_date`),
  KEY `idx_death_date` (`death_date`),
  KEY `idx_full_name` (`surname`,`given_name`),
  KEY `fk_person_photo` (`primary_photo_id`),
  KEY `idx_tree_id_spouse_lookup` (`tree_id`,`sex`),
  KEY `idx_tree_birth_year` (`tree_id`,`birth_date`(10)),
  KEY `idx_tree_death_year` (`tree_id`,`death_date`(10)),
  KEY `idx_persons_uid` (`uid`),
  KEY `idx_birth_place_id` (`birth_place_id`),
  KEY `idx_death_place_id` (`death_place_id`),
  KEY `idx_burial_place_id` (`burial_place_id`),
  KEY `genealogy_persons_rag_indexed_at_index` (`rag_indexed_at`),
  CONSTRAINT `fk_person_birth_place` FOREIGN KEY (`birth_place_id`) REFERENCES `genealogy_places` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_person_burial_place` FOREIGN KEY (`burial_place_id`) REFERENCES `genealogy_places` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_person_death_place` FOREIGN KEY (`death_place_id`) REFERENCES `genealogy_places` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_person_photo` FOREIGN KEY (`primary_photo_id`) REFERENCES `genealogy_media` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_person_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_place_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_place_aliases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `place_id` int unsigned NOT NULL,
  `alias` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_alias` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `alias_type` enum('spelling','historical','abbreviation','translation','common') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'spelling',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_normalized_alias` (`normalized_alias`),
  KEY `idx_place` (`place_id`),
  CONSTRAINT `fk_alias_place` FOREIGN KEY (`place_id`) REFERENCES `genealogy_places` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_place_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_place_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `place_id` int unsigned NOT NULL,
  `historical_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_type` enum('official','common','variant','native') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'official',
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_place` (`place_id`),
  KEY `idx_dates` (`valid_from`,`valid_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_places`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_places` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display name (e.g., Philadelphia, Pennsylvania, USA)',
  `normalized_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Lowercase, no punctuation for matching',
  `short_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Short form (e.g., Philadelphia)',
  `parent_id` int unsigned DEFAULT NULL COMMENT 'Hierarchy: city -> county -> state -> country',
  `place_type` enum('country','state','county','city','township','district','address','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `historical_boundaries` json DEFAULT NULL COMMENT 'Array of {start_year, end_year, boundary_geojson}',
  `aliases` json DEFAULT NULL COMMENT 'Array of alternate names/spellings',
  `external_ids` json DEFAULT NULL COMMENT 'FamilySearch, Wikidata, GeoNames IDs',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_normalized_name` (`normalized_name`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_place_type` (`place_type`),
  KEY `idx_coords` (`latitude`,`longitude`),
  FULLTEXT KEY `ft_name` (`name`,`short_name`),
  CONSTRAINT `fk_place_parent` FOREIGN KEY (`parent_id`) REFERENCES `genealogy_places` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_proposed_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_proposed_changes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL,
  `change_type` enum('fact_update','event_add','source_add','media_link','notes_append','residence_add','family_event_update','external_record_link','source_create','clipping_link','media_metadata_update') NOT NULL,
  `field_name` varchar(100) DEFAULT NULL,
  `current_value` text,
  `proposed_value` text NOT NULL,
  `evidence_sources` text COMMENT 'JSON array',
  `evidence_summary` text NOT NULL,
  `provenance_json` json DEFAULT NULL,
  `confidence` decimal(3,2) DEFAULT '0.50',
  `agent_id` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `applied_at` timestamp NULL DEFAULT NULL,
  `reviewer_notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree_status` (`tree_id`,`status`),
  KEY `idx_person` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_proposed_relationships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_proposed_relationships` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL COMMENT 'Existing person in tree',
  `related_person_id` int unsigned DEFAULT NULL COMMENT 'Existing related person when proposal links two existing people',
  `relationship_type` varchar(20) NOT NULL COMMENT 'parent, child, sibling, spouse',
  `proposal_mode` varchar(32) NOT NULL DEFAULT 'create_person' COMMENT 'create_person or link_existing',
  `proposed_name` varchar(255) NOT NULL COMMENT 'Full name of proposed relative',
  `proposed_given_name` varchar(100) DEFAULT NULL,
  `proposed_surname` varchar(100) DEFAULT NULL,
  `proposed_sex` char(1) DEFAULT NULL COMMENT 'M, F, or NULL if unknown',
  `proposed_birth_date` varchar(50) DEFAULT NULL COMMENT 'GEDCOM date format',
  `proposed_birth_place` varchar(255) DEFAULT NULL,
  `proposed_death_date` varchar(50) DEFAULT NULL,
  `proposed_death_place` varchar(255) DEFAULT NULL,
  `proposed_marriage_date` varchar(50) DEFAULT NULL COMMENT 'For spouse proposals: marriage date in GEDCOM format',
  `proposed_marriage_place` varchar(255) DEFAULT NULL COMMENT 'For spouse proposals: marriage location',
  `proposed_occupation` varchar(255) DEFAULT NULL COMMENT 'Proposed person occupation',
  `proposed_notes` text COMMENT 'Research notes to attach to new person',
  `evidence_sources` text COMMENT 'JSON array of source citations',
  `evidence_summary` text COMMENT 'How the relationship was determined',
  `confidence` decimal(3,2) NOT NULL DEFAULT '0.50',
  `agent_id` varchar(100) DEFAULT NULL COMMENT 'Agent that proposed this',
  `review_id` int unsigned DEFAULT NULL COMMENT 'FK to agent_review_queue',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, approved, rejected, applied',
  `applied_person_id` int unsigned DEFAULT NULL COMMENT 'Person ID created when applied',
  `applied_family_id` int unsigned DEFAULT NULL COMMENT 'Family ID created/used when applied',
  `applied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree_status` (`tree_id`,`status`),
  KEY `idx_person` (`person_id`),
  KEY `idx_review` (`review_id`),
  KEY `idx_related_person` (`related_person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_provider_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_provider_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tree_id` int unsigned DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'search, get_record, oauth_callback, etc.',
  `request_data` json DEFAULT NULL COMMENT 'Request parameters (sanitized)',
  `response_summary` json DEFAULT NULL COMMENT 'Response metadata (not full data)',
  `success` tinyint(1) DEFAULT '1',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `response_time_ms` int unsigned DEFAULT NULL,
  `rate_limit_remaining` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_provider_date` (`provider_id`,`created_at`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_action` (`action`),
  KEY `idx_success` (`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_provider_sync_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_provider_sync_status` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `provider_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sync_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'persons, records, hints, dna_matches',
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `last_sync_status` enum('success','partial','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `records_synced` int unsigned DEFAULT '0',
  `records_updated` int unsigned DEFAULT '0',
  `records_failed` int unsigned DEFAULT '0',
  `sync_cursor` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Pagination cursor for incremental sync',
  `sync_metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tree_provider_type` (`tree_id`,`provider_id`,`sync_type`),
  KEY `idx_provider` (`provider_id`),
  CONSTRAINT `fk_sync_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_provider_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_provider_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `provider_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'familysearch, ancestry_dna, myheritage, etc.',
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'OAuth2 access token or session token',
  `refresh_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'OAuth2 refresh token',
  `token_expires_at` timestamp NULL DEFAULT NULL COMMENT 'When access token expires',
  `token_data` json DEFAULT NULL COMMENT 'Additional token metadata',
  `external_user_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'User ID on external provider',
  `external_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Username on external provider',
  `is_active` tinyint(1) DEFAULT '1',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tree_provider` (`tree_id`,`provider_id`),
  KEY `idx_provider` (`provider_id`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `fk_provider_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_reports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `report_type` enum('ahnentafel','descendant','pedigree','family_group') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `person_id` int unsigned DEFAULT NULL,
  `tree_id` int unsigned NOT NULL,
  `parameters` json DEFAULT NULL,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `format` enum('html','pdf','text') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'html',
  `generated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reports_type` (`report_type`),
  KEY `idx_reports_person` (`person_id`),
  KEY `idx_reports_tree` (`tree_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_repositories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_repositories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `gedcom_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree_gedcom` (`tree_id`,`gedcom_id`),
  CONSTRAINT `fk_repository_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_research_fact_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_research_fact_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `research_fact_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `genealogy_person_id` bigint unsigned NOT NULL,
  `fact_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_grfl_fact_person_type` (`research_fact_id`,`genealogy_person_id`,`fact_type`),
  KEY `idx_grfl_person_type` (`genealogy_person_id`,`fact_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_research_hints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_research_hints` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned DEFAULT NULL,
  `hint_type` enum('record_match','name_variation','location_suggestion','date_correction','relationship_suggestion','missing_info','duplicate_warning') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `confidence` decimal(3,2) DEFAULT '0.50',
  `source_info` json DEFAULT NULL,
  `record_source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_record_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matching_criteria` json DEFAULT NULL,
  `suggested_record_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `record_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auto_generated` tinyint NOT NULL DEFAULT '0',
  `status` enum('pending','accepted','rejected','deferred') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`hint_type`),
  KEY `idx_genealogy_research_hints_status` (`status`),
  KEY `idx_research_hints_record_source` (`record_source`,`external_record_id`),
  KEY `idx_research_hints_auto_generated` (`auto_generated`,`created_at`),
  CONSTRAINT `fk_hint_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hint_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_research_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_research_providers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `provider_id` varchar(50) NOT NULL,
  `provider_name` varchar(100) NOT NULL,
  `provider_class` varchar(255) DEFAULT NULL COMMENT 'PHP class path if framework-integrated',
  `provider_type` enum('api','scrape','oauth2','manual') NOT NULL DEFAULT 'api',
  `base_url` varchar(500) DEFAULT NULL,
  `api_key_env` varchar(100) DEFAULT NULL COMMENT 'Env var name holding API key/creds',
  `api_key` varchar(500) DEFAULT NULL,
  `auth_type` enum('none','api_key','oauth2','cookie','session') NOT NULL DEFAULT 'none',
  `capabilities` json DEFAULT NULL COMMENT 'What this provider can do: search_persons, search_records, etc.',
  `config` json DEFAULT NULL COMMENT 'Provider-specific config: endpoints, rate limits, etc.',
  `rate_limit_rpm` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_authenticated` tinyint(1) NOT NULL DEFAULT '0',
  `priority` tinyint NOT NULL DEFAULT '50',
  `signup_url` varchar(500) DEFAULT NULL,
  `notes` text,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `last_error` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider_id` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_research_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_research_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL,
  `person_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority_score` decimal(5,3) NOT NULL DEFAULT '0.000',
  `priority_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `question_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `research_question` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `selection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','in_progress','completed','skipped','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `assessed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `session_id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `findings_count` int unsigned DEFAULT '0',
  `review_items_count` int unsigned DEFAULT '0',
  `last_task_id` int unsigned DEFAULT NULL,
  `last_outcome_state` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_outcome_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`,`priority_score` DESC),
  KEY `idx_tree_status` (`tree_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_research_queue_archive_20260411`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_research_queue_archive_20260411` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL,
  `person_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority_score` decimal(5,3) NOT NULL DEFAULT '0.000',
  `priority_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','in_progress','completed','skipped','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `assessed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `session_id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `findings_count` int unsigned DEFAULT '0',
  `review_items_count` int unsigned DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`,`priority_score` DESC),
  KEY `idx_tree_status` (`tree_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_research_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_research_searches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned DEFAULT NULL COMMENT 'If search was for specific person',
  `search_query` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `search_params` json DEFAULT NULL COMMENT 'Full search parameters',
  `sources_searched` json DEFAULT NULL COMMENT 'Array of source codes',
  `total_results` int DEFAULT '0',
  `clippings_created` int DEFAULT '0',
  `matches_found` int DEFAULT '0',
  `search_duration_ms` int DEFAULT NULL,
  `searched_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_searched` (`searched_at`),
  CONSTRAINT `fk_search_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_search_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_research_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_research_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned DEFAULT NULL,
  `queue_item_id` bigint unsigned DEFAULT NULL,
  `task_type` enum('find_records','verify_facts','find_relatives','analyze_dna','suggest_sources','transcribe_document') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `research_question` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `selection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `scope_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `related_people_used` json DEFAULT NULL,
  `sources_checked` json DEFAULT NULL,
  `evidence_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `conflicts_found` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `outcome_state` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `outcome_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `priority` enum('low','medium','high','urgent') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'medium',
  `status` enum('queued','processing','completed','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'queued',
  `parameters` json DEFAULT NULL,
  `results` json DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`task_type`),
  CONSTRAINT `fk_task_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_research_tasks_archive_20260411`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_research_tasks_archive_20260411` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned DEFAULT NULL,
  `task_type` enum('find_records','verify_facts','find_relatives','analyze_dna','suggest_sources','transcribe_document') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` enum('low','medium','high','urgent') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'medium',
  `status` enum('queued','processing','completed','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'queued',
  `parameters` json DEFAULT NULL,
  `results` json DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`task_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_residences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_residences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `residence_date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `place` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `source_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date` (`residence_date`),
  KEY `fk_residence_source` (`source_id`),
  KEY `idx_person_date` (`person_id`,`residence_date`(10)),
  CONSTRAINT `fk_residence_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_residence_source` FOREIGN KEY (`source_id`) REFERENCES `genealogy_sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_schema_extensions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_schema_extensions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `extension_tag` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension_uri` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_schema_ext_tree_tag` (`tree_id`,`extension_tag`),
  CONSTRAINT `fk_schema_ext_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_search_coverage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_search_coverage` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `tree_id` int unsigned NOT NULL,
  `repository_type` enum('vital_records','census','church','military','immigration','land','probate','newspaper','cemetery','dna','newspaper_digital','family_tree_aggregator','state_archives','county_records','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `repository_name` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Specific repository (e.g. FamilySearch, NARA, LOC ChronAm)',
  `search_count` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Total searches attempted',
  `positive_count` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Searches that returned results',
  `negative_count` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Searches that returned nothing',
  `date_ranges_covered` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'JSON array of date ranges searched',
  `geographic_areas_covered` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'JSON array of geographic areas searched',
  `last_searched_at` timestamp NULL DEFAULT NULL,
  `coverage_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Notes on coverage gaps, exclusions, access issues',
  `gps_satisfactory` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = agent/human determined this repository adequately covered',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_repo` (`person_id`,`repository_type`,`repository_name`(200)),
  KEY `idx_tree_id` (`tree_id`),
  KEY `idx_gps` (`person_id`,`gps_satisfactory`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_shared_note_refs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_shared_note_refs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `shared_note_id` int unsigned NOT NULL,
  `record_type` enum('person','family','source','media','repository') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snote_ref_note` (`shared_note_id`),
  KEY `idx_snote_ref_record` (`record_type`,`record_id`),
  CONSTRAINT `fk_snote_ref_note` FOREIGN KEY (`shared_note_id`) REFERENCES `genealogy_shared_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_shared_note_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_shared_note_translations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `shared_note_id` int unsigned NOT NULL,
  `language` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `translated_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_snote_trans_note` (`shared_note_id`),
  CONSTRAINT `fk_snote_trans_note` FOREIGN KEY (`shared_note_id`) REFERENCES `genealogy_shared_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_shared_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_shared_notes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `gedcom_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `language` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shared_notes_tree` (`tree_id`),
  KEY `idx_shared_notes_uid` (`uid`),
  CONSTRAINT `fk_shared_notes_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_smart_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_smart_matches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL,
  `match_source` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `match_data` json NOT NULL,
  `confidence` decimal(3,2) DEFAULT '0.50',
  `status` enum('pending','accepted','rejected','merged') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_status` (`status`),
  KEY `idx_source` (`match_source`),
  CONSTRAINT `fk_match_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_match_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_source_conflicts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_source_conflicts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `tree_id` int unsigned NOT NULL,
  `field_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Which fact conflicts (birth_date, birth_place, etc.)',
  `source_a_id` int unsigned DEFAULT NULL COMMENT 'First conflicting source',
  `source_a_value` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Value claimed by source A',
  `source_a_quality` enum('original','derivative','authored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_b_id` int unsigned DEFAULT NULL COMMENT 'Second conflicting source',
  `source_b_value` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Value claimed by source B',
  `source_b_quality` enum('original','derivative','authored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conflict_severity` enum('minor','moderate','major') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'moderate' COMMENT 'minor=spelling variant, moderate=±5yr, major=contradictory facts',
  `resolution_status` enum('unresolved','resolved','ignored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unresolved',
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'How the conflict was resolved (GPS analysis)',
  `resolved_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Agent or human who resolved',
  `resolved_at` timestamp NULL DEFAULT NULL,
  `detected_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Agent that detected this conflict',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conflict` (`person_id`,`field_name`,`source_a_id`,`source_b_id`),
  KEY `idx_tree_id` (`tree_id`),
  KEY `idx_resolution_status` (`resolution_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_source_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_source_metrics` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tool_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `person_id` int unsigned DEFAULT NULL,
  `tree_id` int unsigned DEFAULT NULL,
  `agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `query_params` json DEFAULT NULL,
  `result_count` int NOT NULL DEFAULT '0',
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `duration_ms` int NOT NULL DEFAULT '0',
  `ran_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_id` (`source_id`),
  KEY `idx_tool_name` (`tool_name`),
  KEY `idx_ran_at` (`ran_at`),
  KEY `idx_person_id` (`person_id`),
  KEY `idx_tree_id` (`tree_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_source_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_source_registry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `archive_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archive_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `record_types` json NOT NULL COMMENT 'Array of record types: vital, census, church, military, immigration, land, probate, newspaper, cemetery, death, family_tree, obituary, labor',
  `eras` json DEFAULT NULL COMMENT 'Array of applicable eras: colonial, revolutionary, antebellum, civil_war, gilded_age, progressive, interwar, modern, all',
  `regions` json DEFAULT NULL COMMENT 'Array of applicable regions: new_england, mid_atlantic, south, midwest, great_plains, southwest, west, uk_ireland, scandinavia, france, eastern_europe, italy, canada, german_origin, all',
  `ethnicities` json DEFAULT NULL COMMENT 'Array: african_american, jewish, default, all',
  `tool_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'FK to agent_tool_registry.name — null if no automated tool',
  `priority` tinyint unsigned NOT NULL DEFAULT '5' COMMENT '1=highest, 8=lowest',
  `coverage_start_year` smallint unsigned DEFAULT NULL COMMENT 'Earliest year of records',
  `coverage_end_year` smallint unsigned DEFAULT NULL COMMENT 'Latest year of records',
  `access_type` enum('free','subscription','library','foia','mixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'free',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `search_count` int unsigned NOT NULL DEFAULT '0',
  `hit_count` int unsigned NOT NULL DEFAULT '0',
  `last_searched_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_source_registry_tool` (`tool_name`),
  KEY `idx_source_registry_active_priority` (`is_active`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_sources` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `gedcom_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uid` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `publication` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `repository` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `repository_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `call_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_quality` enum('original','derivative','authored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'GPS source category: original=created at time, derivative=copy/transcription, authored=compiled narrative',
  `quality_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Notes on source quality assessment',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `source_category` enum('original','derivative','authored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'GPS source type: original=first recording, derivative=copy/abstract, authored=compiled narrative',
  `information_quality` enum('primary','secondary','undetermined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'GPS information quality: primary=firsthand, secondary=secondhand/later, undetermined=unknown',
  `classification_confidence` decimal(5,4) DEFAULT NULL COMMENT 'AI confidence score for auto-classification (0.0000-1.0000)',
  `classification_method` enum('auto','manual','ai_suggested') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'How classification was determined',
  `classification_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Notes explaining classification reasoning',
  `classified_at` timestamp NULL DEFAULT NULL COMMENT 'When source was classified',
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tree_gedcom` (`tree_id`,`gedcom_id`),
  KEY `idx_title` (`title`(100)),
  KEY `idx_sources_classification` (`tree_id`,`source_category`,`information_quality`),
  KEY `idx_sources_uid` (`uid`),
  CONSTRAINT `fk_source_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_tree_collaborators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_tree_collaborators` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `role` enum('viewer','contributor','editor','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'viewer',
  `can_export` tinyint(1) DEFAULT '0',
  `can_delete` tinyint(1) DEFAULT '0',
  `can_manage_media` tinyint(1) DEFAULT '1',
  `invited_by` int unsigned DEFAULT NULL,
  `invited_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tree_user` (`tree_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_collab_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_tree_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_tree_invitations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tree_id` int unsigned NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('viewer','contributor','editor','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'viewer',
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invited_by` int unsigned DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_email` (`email`),
  CONSTRAINT `fk_invite_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_trees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_trees` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `owner_id` int unsigned DEFAULT NULL,
  `privacy` enum('private','shared','public') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'private',
  `living_privacy` enum('hide_all','hide_details','show_all') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'hide_details',
  `living_years_threshold` int DEFAULT '100',
  `default_media_privacy` enum('private','shared','public') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'shared',
  `allow_public_search` tinyint(1) DEFAULT '0',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `root_person_id` int unsigned DEFAULT NULL COMMENT 'Tree owner / central person; starting point for ancestor BFS and UI default',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_file` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gedcom_version` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '5.5.1',
  `default_language` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'en',
  `import_date` timestamp NULL DEFAULT NULL,
  `person_count` int DEFAULT '0',
  `family_count` int DEFAULT '0',
  `media_count` int DEFAULT '0',
  `source_count` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genealogy_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genealogy_versions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` int unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `old_data` json DEFAULT NULL,
  `new_data` json DEFAULT NULL,
  `diff_summary` json DEFAULT NULL,
  `change_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changed_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_version` (`entity_type`,`entity_id`,`version_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gps_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gps_assessments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL,
  `exhaustive_search_score` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '0-100 score',
  `exhaustive_search_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `repositories_checked` json DEFAULT NULL COMMENT 'List of repositories searched',
  `repositories_remaining` json DEFAULT NULL COMMENT 'Repositories still to check',
  `source_citations_complete` tinyint(1) NOT NULL DEFAULT '0',
  `citation_issues` json DEFAULT NULL COMMENT 'List of incomplete/inaccurate citations',
  `evidence_analysis_complete` tinyint(1) NOT NULL DEFAULT '0',
  `direct_evidence_count` int unsigned DEFAULT '0',
  `indirect_evidence_count` int unsigned DEFAULT '0',
  `negative_evidence_count` int unsigned DEFAULT '0',
  `evidence_correlation_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `conflicting_evidence_exists` tinyint(1) NOT NULL DEFAULT '0',
  `conflicting_evidence_resolved` tinyint(1) NOT NULL DEFAULT '0',
  `conflict_resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sound_conclusion` tinyint(1) NOT NULL DEFAULT '0',
  `conclusion_reasoning` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `overall_score` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '0-100 composite GPS score',
  `gps_compliant` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'TRUE if all 5 elements satisfied',
  `assessor_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `assessed_by` int unsigned DEFAULT NULL COMMENT 'User who performed assessment',
  `assessed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_compliant` (`gps_compliant`),
  KEY `idx_score` (`overall_score`),
  CONSTRAINT `fk_gps_assess_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gps_assess_task` FOREIGN KEY (`task_id`) REFERENCES `gps_research_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gps_research_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gps_research_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL,
  `person_id` int unsigned NOT NULL,
  `log_type` enum('search','analysis','conclusion','note') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'search',
  `repository_searched` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Name of repository/archive searched',
  `repository_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_terms` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Exact search terms used',
  `date_range_searched` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Date range covered (e.g., 1850-1870)',
  `location_searched` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Geographic area searched',
  `record_types_searched` json DEFAULT NULL COMMENT 'Types of records searched (census, vital, church, etc.)',
  `results_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'What was found or not found',
  `negative_result` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'TRUE if search yielded no relevant results',
  `source_ids_found` json DEFAULT NULL COMMENT 'Array of source IDs found/created',
  `media_ids_found` json DEFAULT NULL COMMENT 'Array of media IDs found/created',
  `search_duration_minutes` int unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `searched_at` timestamp NULL DEFAULT NULL COMMENT 'When the search was performed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_negative` (`negative_result`),
  KEY `idx_repository` (`repository_searched`),
  KEY `idx_log_type` (`log_type`),
  CONSTRAINT `fk_gps_log_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gps_log_task` FOREIGN KEY (`task_id`) REFERENCES `gps_research_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gps_research_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gps_research_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int unsigned NOT NULL,
  `tree_id` int unsigned NOT NULL,
  `task_type` enum('birth','death','marriage','parentage','identity','location','occupation','migration','military','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `question` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The specific research question being investigated',
  `hypothesis` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Proposed answer or working theory',
  `status` enum('open','in_progress','resolved','inconclusive','abandoned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `priority` enum('low','medium','high','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `assigned_to` int unsigned DEFAULT NULL COMMENT 'User ID if assigned',
  `conclusion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Final written conclusion (GPS element 5)',
  `evidence_summary` json DEFAULT NULL COMMENT 'Summary of direct/indirect/negative evidence',
  `due_date` date DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_person` (`person_id`),
  KEY `idx_tree` (`tree_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_type_status` (`task_type`,`status`),
  CONSTRAINT `fk_gps_task_person` FOREIGN KEY (`person_id`) REFERENCES `genealogy_persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gps_task_tree` FOREIGN KEY (`tree_id`) REFERENCES `genealogy_trees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gps_standard_repositories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gps_standard_repositories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` enum('vital_records','census','church','military','immigration','land','probate','newspaper','cemetery','dna','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `geographic_coverage` json DEFAULT NULL COMMENT 'Countries/states covered',
  `temporal_coverage` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Date range covered',
  `is_free` tinyint(1) NOT NULL DEFAULT '0',
  `requires_subscription` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Subscription type needed',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_name` (`name`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `guardrail_confirmations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guardrail_confirmations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `confirmed_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `guardrail_confirmations_token_unique` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `guardrail_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guardrail_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `guardrail_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guardrail_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation_pattern` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'log',
  `conditions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `severity` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `agent_scope` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guardrail_rules_operation_pattern_index` (`operation_pattern`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `human_review_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `human_review_outcomes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_id` bigint unsigned NOT NULL,
  `reviewer_id` bigint unsigned DEFAULT NULL,
  `decision` enum('approve','reject','modify') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `modifications` json DEFAULT NULL COMMENT 'Changes made if decision=modify',
  `original_values` json DEFAULT NULL,
  `final_values` json DEFAULT NULL,
  `review_duration_seconds` int DEFAULT NULL COMMENT 'Time spent reviewing',
  `confidence_after` decimal(5,4) DEFAULT NULL COMMENT 'Confidence after human review',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `human_review_outcomes_reviewer_id_foreign` (`reviewer_id`),
  KEY `human_review_outcomes_task_id_index` (`task_id`),
  KEY `human_review_outcomes_decision_index` (`decision`),
  CONSTRAINT `human_review_outcomes_reviewer_id_foreign` FOREIGN KEY (`reviewer_id`) REFERENCES `human_reviewers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `human_review_outcomes_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `human_review_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `human_review_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `human_review_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'verdict_review, evidence_review, contradiction_resolution',
  `reference_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'claim, verdict, evidence, pipeline',
  `reference_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ID of the item to review',
  `pipeline_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Associated fact-check pipeline',
  `confidence_score` decimal(5,4) NOT NULL COMMENT 'Original AI confidence score',
  `priority` int NOT NULL DEFAULT '50' COMMENT '1-100, higher = more urgent',
  `title` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `context` json DEFAULT NULL COMMENT 'Full context for review',
  `ai_recommendation` json DEFAULT NULL COMMENT 'AI suggested action',
  `status` enum('pending','assigned','in_review','completed','escalated','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `assigned_to` bigint unsigned DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `due_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `human_review_tasks_status_priority_index` (`status`,`priority`),
  KEY `human_review_tasks_status_created_at_index` (`status`,`created_at`),
  KEY `human_review_tasks_task_type_status_index` (`task_type`,`status`),
  KEY `human_review_tasks_reference_id_index` (`reference_id`),
  KEY `human_review_tasks_pipeline_id_index` (`pipeline_id`),
  KEY `human_review_tasks_priority_index` (`priority`),
  KEY `human_review_tasks_assigned_to_index` (`assigned_to`),
  CONSTRAINT `human_review_tasks_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `human_reviewers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `human_reviewers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `human_reviewers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'User identifier',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expertise_areas` json DEFAULT NULL COMMENT 'Areas of expertise for task routing',
  `max_concurrent_tasks` int NOT NULL DEFAULT '5',
  `current_task_count` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `human_reviewers_user_id_unique` (`user_id`),
  KEY `human_reviewers_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `joplin_attachment_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `joplin_attachment_index` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `note_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Joplin note ID',
  `resource_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Joplin resource ID',
  `filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT NULL,
  `content_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'MD5 hash of attachment content',
  `extraction_version` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v2' COMMENT 'Pipeline version used',
  `extraction_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g., pdftotext+claude, tesseract+claude',
  `sync_status` enum('pending','queued','processing','synced','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `last_processed_at` timestamp NULL DEFAULT NULL,
  `error_log` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `extracted_entities` json DEFAULT NULL COMMENT 'Structured entities from extraction',
  `media_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nextcloud WebDAV URL for source media',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_note_resource_unique` (`note_id`,`resource_id`),
  KEY `idx_resource_id` (`resource_id`),
  KEY `idx_sync_status` (`sync_status`),
  KEY `idx_extraction_version` (`extraction_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `joplin_metadata_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `joplin_metadata_cache` (
  `id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `preview` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `parent_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` int NOT NULL DEFAULT '1',
  `created_time` timestamp NULL DEFAULT NULL,
  `updated_time` timestamp NULL DEFAULT NULL,
  `user_created_time` timestamp NULL DEFAULT NULL,
  `user_updated_time` timestamp NULL DEFAULT NULL,
  `is_conflict` tinyint(1) NOT NULL DEFAULT '0',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `file_size` int DEFAULT NULL,
  `markup_language` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'markdown',
  `cached_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `joplin_metadata_cache_parent_id_index` (`parent_id`),
  KEY `joplin_metadata_cache_type_index` (`type`),
  KEY `joplin_metadata_cache_updated_time_index` (`updated_time`),
  KEY `joplin_metadata_cache_cached_at_index` (`cached_at`),
  KEY `joplin_metadata_cache_is_deleted_index` (`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `joplin_queue_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `joplin_queue_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operation_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `note_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT '0',
  `max_attempts` int NOT NULL DEFAULT '5',
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `joplin_queue_jobs_status_index` (`status`),
  KEY `joplin_queue_jobs_next_attempt_at_index` (`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `llm_cascade_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `llm_cascade_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prompt_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 of prompt for dedup analysis',
  `caller` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Agent name or service that called process()',
  `initial_provider` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `initial_model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `escalated` tinyint(1) NOT NULL DEFAULT '0',
  `escalation_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `escalated_provider` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `escalated_model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quality_score` decimal(5,4) DEFAULT NULL COMMENT 'Aggregate quality score 0.0–1.0',
  `signals` json DEFAULT NULL COMMENT 'Per-signal scores',
  `latency_initial_ms` int unsigned DEFAULT NULL,
  `latency_escalated_ms` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lcl_escalated` (`escalated`),
  KEY `idx_lcl_caller` (`caller`),
  KEY `idx_lcl_created_at` (`created_at`),
  KEY `idx_lcl_provider` (`initial_provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `llm_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `llm_instances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique identifier (e.g., ollama_primary, ollama_secondary_1, claude_cli)',
  `instance_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-friendly name (e.g., Primary GPU Server)',
  `instance_type` enum('ollama','claude_cli','codex_cli','anthropic_api','openai','azure_openai','google_gemini','local_llm','custom') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Provider type for adapter selection',
  `base_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'API endpoint URL (null for CLI-based)',
  `port` int DEFAULT NULL COMMENT 'Port if separate from URL',
  `api_key_env` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Env var name for API key (never store keys directly)',
  `api_key` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT '50' COMMENT 'Routing priority (1=highest, 100=lowest)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Manually enabled/disabled',
  `routability` enum('allowed','bench_only','blocked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'blocked',
  `gpu_target` enum('pascal_6gb','ada_12gb','any','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `host_affinity` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compat_runtime_family` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compat_backend` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compat_status` enum('authoritative','provisional','stale') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'provisional',
  `is_healthy` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Current health status',
  `health_score` tinyint NOT NULL DEFAULT '100' COMMENT 'Dynamic health score 0-100',
  `capabilities` json NOT NULL COMMENT '["text", "vision", "embedding", "tools", "streaming"]',
  `is_censored` tinyint(1) NOT NULL DEFAULT '1',
  `allows_private_data` tinyint(1) NOT NULL DEFAULT '0',
  `data_privacy_scope` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public_only',
  `privacy_reviewed_at` timestamp NULL DEFAULT NULL,
  `privacy_notes` text COLLATE utf8mb4_unicode_ci,
  `supported_models` json DEFAULT NULL COMMENT 'List of model names this instance supports',
  `context_length` int unsigned DEFAULT NULL COMMENT 'Max context window in tokens for this provider',
  `embedding_context_length` int unsigned DEFAULT NULL COMMENT 'Max embedding input in tokens (if different from context_length)',
  `avg_response_ms` decimal(10,2) DEFAULT NULL COMMENT 'Moving average response time',
  `p95_response_ms` decimal(10,2) DEFAULT NULL COMMENT '95th percentile response time',
  `total_requests` int NOT NULL DEFAULT '0',
  `total_failures` int NOT NULL DEFAULT '0',
  `consecutive_failures` int NOT NULL DEFAULT '0' COMMENT 'For circuit breaker',
  `success_rate` decimal(5,2) DEFAULT NULL COMMENT 'Calculated success percentage',
  `circuit_state` enum('closed','open','half_open') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'closed',
  `circuit_opened_at` timestamp NULL DEFAULT NULL,
  `circuit_retry_at` timestamp NULL DEFAULT NULL COMMENT 'When to attempt half-open',
  `max_concurrent` tinyint NOT NULL DEFAULT '1' COMMENT 'Max concurrent requests (1 for single-GPU Ollama)',
  `rate_limit_rpm` int DEFAULT NULL COMMENT 'Requests per minute limit',
  `rate_limit_tpm` int DEFAULT NULL COMMENT 'Tokens per minute limit',
  `cost_per_1k_input` decimal(8,6) DEFAULT NULL COMMENT 'Cost per 1K input tokens',
  `cost_per_1k_output` decimal(8,6) DEFAULT NULL COMMENT 'Cost per 1K output tokens',
  `cost_tier` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'free, low, medium, high, premium',
  `config` json DEFAULT NULL COMMENT 'Provider-specific config (timeouts, retries, etc.)',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Admin notes',
  `last_health_check` timestamp NULL DEFAULT NULL,
  `last_success_at` timestamp NULL DEFAULT NULL,
  `last_failure_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `llm_instances_instance_id_unique` (`instance_id`),
  KEY `llm_instances_instance_type_index` (`instance_type`),
  KEY `llm_instances_is_active_index` (`is_active`),
  KEY `llm_instances_is_healthy_index` (`is_healthy`),
  KEY `llm_instances_health_score_index` (`health_score`),
  KEY `llm_instances_priority_index` (`priority`),
  KEY `llm_instances_circuit_state_index` (`circuit_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `llm_model_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `llm_model_profiles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `profile_name` varchar(50) NOT NULL,
  `model_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `use_cases` json DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profile_name` (`profile_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `log_analysis_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_analysis_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scanned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `files_scanned` smallint unsigned NOT NULL DEFAULT '0',
  `total_errors` int unsigned NOT NULL DEFAULT '0',
  `unique_signatures` int unsigned NOT NULL DEFAULT '0',
  `bugs_found` smallint unsigned NOT NULL DEFAULT '0',
  `config_issues_found` smallint unsigned NOT NULL DEFAULT '0',
  `transient_count` smallint unsigned NOT NULL DEFAULT '0',
  `alert_by_design_count` smallint unsigned NOT NULL DEFAULT '0',
  `status` enum('completed','partial','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `signature_details` json DEFAULT NULL,
  `findings_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `log_analysis_snapshots_status_index` (`status`),
  KEY `log_analysis_snapshots_scanned_at_status_index` (`scanned_at`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_tool_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_tool_calls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tool_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mcp_server` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mcp_tool` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `caller` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agent' COMMENT 'agent, api, manual',
  `success` tinyint(1) NOT NULL DEFAULT '1',
  `duration_ms` int unsigned DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `params_summary` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'sanitized param keys/types, not values',
  `result_size` int unsigned DEFAULT NULL COMMENT 'bytes of serialized result',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tool_name` (`tool_name`),
  KEY `idx_mcp_server` (`mcp_server`),
  KEY `idx_agent_id` (`agent_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_success` (`success`),
  KEY `idx_caller` (`caller`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `news_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `news_articles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `feed_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `feed_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `article_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `article_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA256 for dedup',
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `content` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `author` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  `bias_score` decimal(5,2) DEFAULT NULL,
  `bias_data` json DEFAULT NULL,
  `workflow_id` int DEFAULT NULL,
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_article_hash` (`article_hash`),
  KEY `idx_feed_url` (`feed_url`(255)),
  KEY `idx_published_at` (`published_at`),
  KEY `idx_rag_indexed` (`rag_indexed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `node_execution_inputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `node_execution_inputs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `node_execution_id` int unsigned NOT NULL,
  `input_key` varchar(255) DEFAULT NULL,
  `input_value` mediumtext,
  PRIMARY KEY (`id`),
  KEY `idx_node_execution` (`node_execution_id`,`input_key`(100)),
  CONSTRAINT `node_execution_inputs_ibfk_1` FOREIGN KEY (`node_execution_id`) REFERENCES `node_executions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `node_execution_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `node_execution_meta` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `node_execution_id` int unsigned NOT NULL,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` text,
  PRIMARY KEY (`id`),
  KEY `node_execution_id` (`node_execution_id`),
  CONSTRAINT `node_execution_meta_ibfk_1` FOREIGN KEY (`node_execution_id`) REFERENCES `node_executions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `node_execution_outputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `node_execution_outputs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `node_execution_id` int unsigned NOT NULL,
  `output_stream` varchar(255) DEFAULT 'default',
  `output_key` varchar(255) DEFAULT NULL,
  `output_value` mediumtext,
  PRIMARY KEY (`id`),
  KEY `idx_execution_stream` (`node_execution_id`,`output_stream`),
  CONSTRAINT `node_execution_outputs_ibfk_1` FOREIGN KEY (`node_execution_id`) REFERENCES `node_executions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `node_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `node_executions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int unsigned NOT NULL,
  `workflow_node_id` int unsigned NOT NULL,
  `node_type` varchar(255) NOT NULL,
  `node_order` int unsigned NOT NULL,
  `duration_ms` int unsigned DEFAULT NULL,
  `error_message` text,
  `executed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `branch_index` int unsigned DEFAULT '0',
  `parent_fan_out_id` varchar(255) DEFAULT NULL,
  `state` enum('pending','running','success','failed','skipped') DEFAULT 'running',
  `timeout_seconds` int unsigned DEFAULT NULL COMMENT 'Timeout applied for this execution',
  `timed_out` tinyint(1) DEFAULT '0' COMMENT 'Whether execution was terminated due to timeout',
  `input` json DEFAULT NULL,
  `output` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_run_order` (`run_id`,`node_order`),
  KEY `idx_executed_at` (`executed_at`),
  KEY `node_executions_ibfk_2` (`workflow_node_id`),
  KEY `idx_node_executions_run_time` (`run_id`,`executed_at`),
  KEY `idx_node_executions_node_type` (`node_type`),
  KEY `idx_fan_out` (`run_id`,`parent_fan_out_id`),
  KEY `idx_branch` (`parent_fan_out_id`,`branch_index`),
  CONSTRAINT `node_executions_ibfk_1` FOREIGN KEY (`run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `node_executions_ibfk_2` FOREIGN KEY (`workflow_node_id`) REFERENCES `workflow_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_access_tokens` (
  `id` char(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `client_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scopes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_access_tokens_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_auth_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_auth_codes` (
  `id` char(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `client_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_auth_codes_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_clients` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redirect_uris` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `grant_types` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_clients_owner_type_owner_id_index` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_device_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_device_codes` (
  `id` char(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `client_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_code` char(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `user_approved_at` datetime DEFAULT NULL,
  `last_polled_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oauth_device_codes_user_code_unique` (`user_code`),
  KEY `oauth_device_codes_user_id_index` (`user_id`),
  KEY `oauth_device_codes_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_refresh_tokens` (
  `id` char(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token_id` char(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_refresh_tokens_access_token_id_index` (`access_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `refresh_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oauth_tokens_provider_unique` (`provider`),
  KEY `oauth_tokens_provider_index` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offline_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offline_audit_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `profile` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `offline_mode_active` tinyint(1) NOT NULL DEFAULT '0',
  `operation` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tool_class` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mcp_server` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mcp_trust_boundary` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `path_class` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_class` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remote_domain_class` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `offline_audit_events_profile_created_at_index` (`profile`,`created_at`),
  KEY `offline_audit_events_event_type_created_at_index` (`event_type`,`created_at`),
  KEY `offline_audit_events_operation_index` (`operation`),
  KEY `offline_audit_events_actor_index` (`actor`),
  KEY `offline_audit_events_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ollama_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ollama_models` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` bigint unsigned DEFAULT NULL COMMENT 'FK to llm_instances - which Ollama instance has this model',
  `model_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Ollama model name (e.g., llama3.1:8b-instruct-q5_K_M)',
  `display_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Human-friendly name',
  `profile` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Profile: default, fast, creative, coding, vision, embedding',
  `status` enum('discovered','testing','vetted','deprecated','unavailable') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'discovered' COMMENT 'discovered=new, testing=being evaluated, vetted=approved for production',
  `is_available` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Currently available on Ollama',
  `capabilities` json DEFAULT NULL COMMENT '["text", "code", "vision", "embedding", "tool_use"]',
  `use_cases` json DEFAULT NULL COMMENT 'Recommended use cases',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `size_gb` decimal(5,2) DEFAULT NULL COMMENT 'Model size in GB',
  `context_length` int DEFAULT NULL COMMENT 'Max context window',
  `vram_required_mb` int DEFAULT NULL COMMENT 'Minimum VRAM needed',
  `avg_tokens_per_second` decimal(8,2) DEFAULT NULL,
  `avg_response_time_ms` decimal(10,2) DEFAULT NULL,
  `total_requests` int NOT NULL DEFAULT '0',
  `total_failures` int NOT NULL DEFAULT '0',
  `success_rate` decimal(5,2) DEFAULT NULL COMMENT 'Calculated success percentage',
  `quality_rating` tinyint DEFAULT NULL COMMENT '1-10 human rating after vetting',
  `vetting_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Human notes from vetting process',
  `vetted_at` timestamp NULL DEFAULT NULL,
  `vetted_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ollama_models_instance_model_unique` (`instance_id`,`model_name`),
  KEY `ollama_models_status_index` (`status`),
  KEY `ollama_models_profile_index` (`profile`),
  KEY `ollama_models_is_available_index` (`is_available`),
  CONSTRAINT `ollama_models_instance_id_foreign` FOREIGN KEY (`instance_id`) REFERENCES `llm_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pipeline_metrics_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pipeline_metrics_snapshots` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_date` date NOT NULL,
  `pipeline` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pending` int unsigned NOT NULL DEFAULT '0',
  `total` int unsigned NOT NULL DEFAULT '0',
  `completion_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `delta_from_prev` int DEFAULT NULL COMMENT 'Change in pending from previous snapshot (negative = progress)',
  `kg_triples_total` int unsigned NOT NULL DEFAULT '0',
  `kg_triples_active` int unsigned NOT NULL DEFAULT '0',
  `kg_triples_missing_source_document` int unsigned NOT NULL DEFAULT '0',
  `kg_triples_orphan_source_document` int unsigned NOT NULL DEFAULT '0',
  `kg_active_missing_either_entity` int unsigned NOT NULL DEFAULT '0',
  `kg_triples_stale_source_hash` int unsigned NOT NULL DEFAULT '0',
  `kg_extracted_documents_without_triples` int unsigned NOT NULL DEFAULT '0',
  `kg_pending_fresh_documents` int unsigned NOT NULL DEFAULT '0',
  `kg_stale_documents` int unsigned NOT NULL DEFAULT '0',
  `kg_hyperedges_total` int unsigned NOT NULL DEFAULT '0',
  `kg_hyperedges_orphan_source_document` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_date_pipeline` (`snapshot_date`,`pipeline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `polarizing_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `polarizing_topics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `keyword` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` int NOT NULL DEFAULT '1',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `polarizing_topics_keyword_unique` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `process_health_flags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `process_health_flags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Source table (e.g., windows_file_actions)',
  `record_id` bigint unsigned NOT NULL COMMENT 'Record ID in the source table',
  `flag_level` enum('warning','flagged','presumed_failed','hard_fail') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'warning' COMMENT 'Current escalation level',
  `flagged_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this flag was first created',
  `escalated_at` timestamp NULL DEFAULT NULL COMMENT 'When last escalated to higher level',
  `cleared_at` timestamp NULL DEFAULT NULL COMMENT 'When flag was cleared (job completed/reset)',
  `clear_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'How the flag was cleared (completed, manual_reset, auto_reset, etc.)',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Additional context about the flag',
  `minutes_since_activity` int DEFAULT NULL COMMENT 'Minutes since last activity when flagged',
  `context_data` json DEFAULT NULL COMMENT 'Additional context (heartbeat status, horizon status, etc.)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_active_flag` (`table_name`,`record_id`,`cleared_at`),
  KEY `idx_flag_level` (`flag_level`),
  KEY `idx_flagged_at` (`flagged_at`),
  KEY `idx_active_by_table` (`table_name`,`cleared_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rag_email_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rag_email_index` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `message_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_date` timestamp NULL DEFAULT NULL,
  `folder` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indexed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_message_hash` (`message_hash`),
  KEY `idx_folder` (`folder`),
  KEY `idx_date` (`message_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recursion_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recursion_config` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `max_depth` tinyint unsigned NOT NULL DEFAULT '1',
  `max_tokens` int unsigned NOT NULL DEFAULT '30000',
  `max_time_seconds` int unsigned NOT NULL DEFAULT '300',
  `max_cost_usd` decimal(8,4) NOT NULL DEFAULT '0.5000',
  `novelty_threshold` decimal(5,4) NOT NULL DEFAULT '0.1500',
  `repetition_threshold` decimal(5,4) NOT NULL DEFAULT '0.9000',
  `decay_window` tinyint unsigned NOT NULL DEFAULT '3',
  `move_on_mode` enum('graceful','hard') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'graceful',
  `strategies` json NOT NULL,
  `sub_call_model_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fast',
  `synthesis_model_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quality',
  `disabled_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disabled_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_name` (`service_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recursion_effectiveness`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recursion_effectiveness` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned DEFAULT NULL,
  `service_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `max_depth_reached` int unsigned NOT NULL DEFAULT '0',
  `total_sub_calls` int unsigned NOT NULL DEFAULT '0',
  `total_tokens` int unsigned NOT NULL DEFAULT '0',
  `total_time_seconds` decimal(8,2) NOT NULL DEFAULT '0.00',
  `total_cost_usd` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `avg_novelty_score` decimal(5,4) DEFAULT NULL,
  `avg_context_window` int unsigned DEFAULT NULL,
  `move_on_count` int unsigned DEFAULT '0',
  `primary_move_on_reason` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quality_improvement_estimate` decimal(5,4) DEFAULT NULL,
  `local_provider_pct` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_re_session` (`session_id`),
  KEY `idx_re_service` (`service_name`),
  KEY `idx_re_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `remediation_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `remediation_actions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `finding_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The type of finding this remediates (e.g. circuit_breaker_open, stalled_job)',
  `action_type` enum('artisan_command','service_method','sql_update') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_target` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Command name, Class::method, or SQL template',
  `action_params` json DEFAULT NULL COMMENT 'Default parameters for the action',
  `risk_level` enum('read','write','destructive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'read',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable description shown in Review Hub',
  `requires_confirmation` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Show confirmation dialog in UI',
  `cooldown_minutes` int unsigned NOT NULL DEFAULT '0' COMMENT 'Min minutes between executions',
  `last_executed_at` timestamp NULL DEFAULT NULL,
  `execution_count` int unsigned NOT NULL DEFAULT '0',
  `success_count` int unsigned NOT NULL DEFAULT '0',
  `failure_count` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_remediation_finding_type` (`finding_type`),
  KEY `idx_remediation_active_risk` (`is_active`,`risk_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `removal_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `removal_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subject_id` bigint unsigned NOT NULL,
  `broker_id` bigint unsigned NOT NULL,
  `status` enum('pending','submitted','awaiting_confirmation','confirmed','failed','verified_removed','reappeared','ignored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `automation_tier` tinyint NOT NULL,
  `submission_method` enum('web_form','email','api','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmation_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmation_email_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Thunderbird message ID',
  `data_found` json DEFAULT NULL COMMENT 'What PII was found on this broker',
  `profile_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fields_to_submit` json DEFAULT NULL COMMENT 'User-selected fields to submit for this request',
  `fields_submitted` json DEFAULT NULL COMMENT 'Actual fields that were submitted',
  `screenshot_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_discovered_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `verified_removed_at` timestamp NULL DEFAULT NULL,
  `next_followup_at` timestamp NULL DEFAULT NULL,
  `recheck_at` timestamp NULL DEFAULT NULL,
  `followup_count` int NOT NULL DEFAULT '0',
  `max_followups` int NOT NULL DEFAULT '3',
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `error_count` int NOT NULL DEFAULT '0',
  `ai_confidence` decimal(5,2) DEFAULT NULL COMMENT 'AI confidence in match 0-100',
  `ai_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `requires_review` tinyint(1) NOT NULL DEFAULT '0',
  `reviewed_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `relisting_detected_at` timestamp NULL DEFAULT NULL,
  `relisting_count` int unsigned NOT NULL DEFAULT '0',
  `last_verification_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `removal_requests_subject_id_index` (`subject_id`),
  KEY `removal_requests_broker_id_index` (`broker_id`),
  KEY `removal_requests_status_index` (`status`),
  KEY `removal_requests_automation_tier_index` (`automation_tier`),
  KEY `removal_requests_requires_review_index` (`requires_review`),
  KEY `removal_requests_next_followup_at_index` (`next_followup_at`),
  KEY `removal_requests_recheck_at_index` (`recheck_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `research_engine_health`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `research_engine_health` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `engine_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('healthy','degraded','failed','unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `last_check_at` timestamp NULL DEFAULT NULL,
  `last_success_at` timestamp NULL DEFAULT NULL,
  `last_failure_at` timestamp NULL DEFAULT NULL,
  `consecutive_failures` int unsigned NOT NULL DEFAULT '0',
  `consecutive_successes` int unsigned NOT NULL DEFAULT '0',
  `total_checks` int unsigned NOT NULL DEFAULT '0',
  `total_successes` int unsigned NOT NULL DEFAULT '0',
  `total_failures` int unsigned NOT NULL DEFAULT '0',
  `total_timeouts` int unsigned NOT NULL DEFAULT '0',
  `avg_response_time_ms` int unsigned DEFAULT NULL,
  `last_error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_error_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `circuit_breaker_state` enum('closed','open','half_open') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'closed',
  `circuit_breaker_opened_at` timestamp NULL DEFAULT NULL,
  `is_api_key_configured` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `chain_position` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Position in fallback chain (1=first)',
  `alert_sent` tinyint(1) NOT NULL DEFAULT '0',
  `alert_sent_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_engine_name` (`engine_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `retry_backoff_intervals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `retry_backoff_intervals` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `retry_config_id` int unsigned NOT NULL,
  `attempt_number` int unsigned NOT NULL,
  `backoff_seconds` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_retry_attempt` (`retry_config_id`,`attempt_number`),
  CONSTRAINT `retry_backoff_intervals_ibfk_1` FOREIGN KEY (`retry_config_id`) REFERENCES `retry_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `retry_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `retry_configs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int unsigned NOT NULL,
  `max_attempts` int DEFAULT '1',
  `notify_on_failure` varchar(255) DEFAULT 'pushover',
  `backoff_strategy` varchar(50) DEFAULT 'exponential',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_workflow_retry` (`workflow_id`),
  CONSTRAINT `retry_configs_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `review_type_registry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `review_type_registry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Internal identifier: agent, research, genealogy, faces, privacy',
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display label: Agent Findings, Research Facts',
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Icon name: robot, magnifying-glass, users',
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Grouping category for tabs',
  `source_table` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Primary table: agent_review_queue, research_facts',
  `source_connection` enum('mysql','pgsql_rag') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mysql',
  `count_sql` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'COUNT query for stats',
  `fetch_sql` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SELECT query with placeholders',
  `approve_sql` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'UPDATE query for approval',
  `reject_sql` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'UPDATE query for rejection',
  `ignore_sql` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'UPDATE query for ignore/dismiss',
  `field_mapping` json NOT NULL COMMENT 'Maps source columns to unified fields',
  `ui_schema` json DEFAULT NULL COMMENT 'Dynamic UI schema for Vue rendering',
  `vue_renderer` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Vue component for custom rendering',
  `vue_detail_component` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Detail panel component',
  `actions` json DEFAULT NULL COMMENT 'Custom actions beyond approve/reject',
  `requires_image` tinyint(1) NOT NULL DEFAULT '0',
  `image_field` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `service_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approve_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reject_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_order` int NOT NULL DEFAULT '100',
  `color` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'UI color class',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_category` (`category`),
  KEY `idx_display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rss_feed_health`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rss_feed_health` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `feed_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `redirect_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Detected redirect destination URL',
  `redirect_count` int NOT NULL DEFAULT '0' COMMENT 'Number of times redirect was detected',
  `redirect_detected_at` timestamp NULL DEFAULT NULL COMMENT 'When redirect was first detected',
  `auto_corrected` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether feed URL was auto-corrected in workflow',
  `auto_corrected_at` timestamp NULL DEFAULT NULL COMMENT 'When auto-correction was applied',
  `original_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Original URL before auto-correction',
  `permanently_dead` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Feed is confirmed dead (no valid RSS at redirect target)',
  `suggested_replacement` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Suggested replacement feed URL',
  `feed_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('healthy','degraded','failed','unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `last_check_at` timestamp NULL DEFAULT NULL,
  `last_success_at` timestamp NULL DEFAULT NULL,
  `last_failure_at` timestamp NULL DEFAULT NULL,
  `consecutive_failures` int NOT NULL DEFAULT '0',
  `consecutive_successes` int NOT NULL DEFAULT '0',
  `avg_response_time_ms` int DEFAULT NULL,
  `articles_fetched_last_success` int NOT NULL DEFAULT '0',
  `total_checks` bigint NOT NULL DEFAULT '0',
  `total_successes` bigint NOT NULL DEFAULT '0',
  `total_failures` bigint NOT NULL DEFAULT '0',
  `total_timeouts` bigint NOT NULL DEFAULT '0',
  `last_error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_error_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feed_title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_items_in_feed` int DEFAULT NULL,
  `feed_last_updated` timestamp NULL DEFAULT NULL,
  `alert_sent` tinyint(1) NOT NULL DEFAULT '0',
  `alert_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rss_feed_health_feed_url_unique` (`feed_url`),
  KEY `rss_feed_health_last_check_at_index` (`last_check_at`),
  KEY `rss_feed_health_status_consecutive_failures_index` (`status`,`consecutive_failures`),
  KEY `idx_rss_health_status_time` (`status`,`last_check_at`),
  KEY `idx_redirect_correction` (`redirect_url`,`auto_corrected`),
  KEY `idx_dead_feeds` (`permanently_dead`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_job_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_job_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scheduled_job_id` int unsigned NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` enum('running','success','failed','timeout') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `output` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Command output or error message',
  `duration_seconds` decimal(10,2) DEFAULT NULL COMMENT 'How long the job ran',
  `items_processed` int unsigned DEFAULT NULL,
  `triggered_by` enum('scheduler','manual','api') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduler',
  `pid` int unsigned DEFAULT NULL,
  `worker_id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_job_started` (`scheduled_job_id`,`started_at`),
  KEY `idx_status` (`status`),
  KEY `idx_pid` (`pid`),
  KEY `idx_worker_id` (`worker_id`),
  CONSTRAINT `scheduled_job_runs_ibfk_1` FOREIGN KEY (`scheduled_job_id`) REFERENCES `scheduled_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique job identifier',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Human-readable description of what this job does',
  `job_type` enum('command','workflow','job_class','agent_task') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'command',
  `command` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Artisan command with args OR workflow name OR job class',
  `cron_expression` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Full cron pattern: minute hour day month weekday',
  `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether job is active',
  `run_in_background` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Run without blocking scheduler',
  `without_overlapping` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Prevent concurrent runs',
  `stall_exempt` tinyint NOT NULL DEFAULT '0' COMMENT 'Skip CPU-based stall detection for I/O-bound jobs',
  `timeout_minutes` int unsigned DEFAULT '60' COMMENT 'Max execution time before timeout',
  `timeout_locked` tinyint(1) NOT NULL DEFAULT '0',
  `last_run_at` timestamp NULL DEFAULT NULL COMMENT 'When job last started',
  `last_completed_at` timestamp NULL DEFAULT NULL COMMENT 'When job last finished',
  `last_run_status` enum('success','failed','running','timeout') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Status of last run',
  `last_run_output` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Output/error from last run',
  `last_pid` int unsigned DEFAULT NULL,
  `max_parallel` tinyint unsigned NOT NULL DEFAULT '1',
  `running_pids` json DEFAULT NULL,
  `running_count` tinyint unsigned NOT NULL DEFAULT '0',
  `next_run_at` timestamp NULL DEFAULT NULL COMMENT 'Calculated next run time',
  `run_count` int unsigned NOT NULL DEFAULT '0' COMMENT 'Total successful runs',
  `fail_count` int unsigned NOT NULL DEFAULT '0' COMMENT 'Total failed runs',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Human notes about this job (why enabled/disabled, issues, etc)',
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Grouping category for UI organization',
  `source_module` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Framework module that owns this job (E13, E22, EA2, Genealogy, etc)',
  `runtime_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `workload_family` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resource_profile` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stall_policy` enum('strict','stall_exempt','adaptive_extend') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `backlog_metric` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_mode` enum('silent','digest','high_priority') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_enabled_next_run` (`enabled`,`next_run_at`),
  KEY `idx_category` (`category`),
  KEY `idx_source_module` (`source_module`),
  KEY `idx_job_type` (`job_type`),
  KEY `idx_last_run_status` (`last_run_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sender_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sender_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Email domain (e.g., amazon.com)',
  `email_pattern` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Regex pattern for email addresses (e.g., noreply@.*)',
  `display_name_pattern` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Regex pattern for display names',
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other' COMMENT 'Auto-assigned category',
  `subcategory` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'More specific categorization',
  `trust_score` decimal(3,2) DEFAULT '0.50' COMMENT 'Trust score 0.00-1.00 (1.00 = fully trusted)',
  `spam_score` decimal(3,2) DEFAULT '0.00' COMMENT 'Spam likelihood 0.00-1.00',
  `interaction_count` int unsigned DEFAULT '0' COMMENT 'Total emails from this sender',
  `reply_count` int unsigned DEFAULT '0' COMMENT 'Emails we replied to',
  `open_count` int unsigned DEFAULT '0' COMMENT 'Emails we opened/read',
  `last_interaction_at` timestamp NULL DEFAULT NULL COMMENT 'Last email received from sender',
  `typical_priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'normal' COMMENT 'Most common priority for this sender',
  `typical_tags` json DEFAULT NULL COMMENT 'Common tags associated with this sender',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Human-added notes about sender',
  `is_newsletter` tinyint(1) DEFAULT '0' COMMENT 'Known newsletter sender',
  `is_transactional` tinyint(1) DEFAULT '0' COMMENT 'Known transactional sender (receipts, confirmations)',
  `is_marketing` tinyint(1) DEFAULT '0' COMMENT 'Known marketing/promotional sender',
  `is_personal` tinyint(1) DEFAULT '0' COMMENT 'Known personal contact',
  `auto_category_override` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Force this category regardless of AI classification',
  `auto_priority_override` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Force this priority regardless of AI classification',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_domain_pattern` (`domain`,`email_pattern`),
  KEY `idx_category` (`category`),
  KEY `idx_trust_score` (`trust_score`),
  KEY `idx_spam_score` (`spam_score`),
  KEY `idx_last_interaction` (`last_interaction_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `skill_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `skill_versions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `skill_name` varchar(100) NOT NULL,
  `version` varchar(20) NOT NULL COMMENT 'Semver from frontmatter',
  `content_hash` varchar(64) NOT NULL COMMENT 'SHA-256 of full SKILL.md content',
  `frontmatter` json NOT NULL COMMENT 'Parsed frontmatter snapshot',
  `body_text` mediumtext NOT NULL COMMENT 'Full markdown body snapshot',
  `full_content` mediumtext NOT NULL COMMENT 'Complete SKILL.md file content for rollback',
  `change_summary` varchar(500) DEFAULT NULL COMMENT 'What changed from previous version',
  `changed_by` varchar(100) DEFAULT 'system' COMMENT 'Who triggered the version: system, agent, human',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Currently deployed version',
  `tools_count` int unsigned NOT NULL DEFAULT '0',
  `tool_phases_count` int unsigned NOT NULL DEFAULT '0',
  `permissions` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skill_hash` (`skill_name`,`content_hash`),
  KEY `idx_skill_active` (`skill_name`,`is_active`),
  KEY `idx_content_hash` (`content_hash`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speculative_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speculative_executions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `spec_run_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_a_mode` enum('agentic','hybrid','deterministic') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_b_mode` enum('agentic','hybrid','deterministic') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_a_session_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_b_session_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_a_job_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_b_job_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','running','arbitrating','completed','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `branch_a_status` enum('pending','running','completed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `branch_b_status` enum('pending','running','completed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `winner` enum('branch_a','branch_b','tie') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `winning_mode` enum('agentic','hybrid','deterministic') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arbitration_reasoning` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quality_uplift_pct` decimal(5,2) DEFAULT NULL,
  `branch_a_tokens` int unsigned DEFAULT '0',
  `branch_b_tokens` int unsigned DEFAULT '0',
  `branch_a_duration_ms` int unsigned DEFAULT '0',
  `branch_b_duration_ms` int unsigned DEFAULT '0',
  `arbitration_tokens` int unsigned DEFAULT '0',
  `total_cost_tokens` int unsigned DEFAULT '0',
  `trigger_type` enum('agent_request','variance_detected','manual','benchmark') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trigger_context` json DEFAULT NULL,
  `branch_a_benchmark_id` bigint unsigned DEFAULT NULL,
  `branch_b_benchmark_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spec_run_id` (`spec_run_id`),
  KEY `idx_agent` (`agent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_trigger` (`trigger_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL,
  `source_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `workflow_id` bigint unsigned DEFAULT NULL,
  `error_id` bigint unsigned DEFAULT NULL,
  `trigger_value` int DEFAULT NULL,
  `threshold_value` int DEFAULT NULL,
  `metric_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `triggered_at` timestamp NOT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `fingerprint` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cooldown_until` timestamp NULL DEFAULT NULL,
  `occurrence_count` int NOT NULL DEFAULT '1',
  `last_occurrence_at` timestamp NULL DEFAULT NULL,
  `notification_sent` tinyint(1) NOT NULL DEFAULT '0',
  `notification_channels` json DEFAULT NULL,
  `notification_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_alerts_alert_type_severity_index` (`alert_type`,`severity`),
  KEY `system_alerts_triggered_at_resolved_at_index` (`triggered_at`,`resolved_at`),
  KEY `system_alerts_fingerprint_cooldown_until_index` (`fingerprint`,`cooldown_until`),
  KEY `system_alerts_severity_index` (`severity`),
  KEY `system_alerts_source_type_index` (`source_type`),
  KEY `system_alerts_source_id_index` (`source_id`),
  KEY `system_alerts_workflow_id_index` (`workflow_id`),
  KEY `system_alerts_error_id_index` (`error_id`),
  KEY `system_alerts_resolved_at_index` (`resolved_at`),
  KEY `system_alerts_cooldown_until_index` (`cooldown_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `section` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `data_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_configs_section_key_unique` (`section`,`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_errors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `error_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Error code like E001, E002',
  `error_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Exception class name',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Error message',
  `error_severity` enum('debug','info','warning','error','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'error',
  `context` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `stack_trace` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'workflow, job, command, api',
  `source_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Workflow ID, Job ID, etc.',
  `workflow_id` int unsigned DEFAULT NULL COMMENT 'If workflow-related',
  `workflow_run_id` int unsigned DEFAULT NULL COMMENT 'If run-related',
  `node_id` int unsigned DEFAULT NULL COMMENT 'If node-related',
  `node_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Node class name',
  `recovery_attempted` tinyint(1) NOT NULL DEFAULT '0',
  `recovery_successful` tinyint(1) DEFAULT NULL,
  `recovery_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'retry, fallback, circuit_breaker',
  `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `duration_ms` int unsigned DEFAULT NULL COMMENT 'Time to resolve in milliseconds',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_errors_error_type_index` (`error_type`),
  KEY `system_errors_error_severity_index` (`error_severity`),
  KEY `system_errors_workflow_id_occurred_at_index` (`workflow_id`,`occurred_at`),
  KEY `system_errors_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `idx_system_errors_error_type` (`error_type`),
  KEY `idx_system_errors_severity` (`error_severity`),
  KEY `idx_system_errors_occurred_type` (`occurred_at`,`error_type`),
  KEY `idx_system_errors_resolved_severity` (`resolved_at`,`error_severity`),
  KEY `idx_system_errors_source` (`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_health_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_health_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `health_score` tinyint unsigned NOT NULL COMMENT '0-100',
  `health_status` enum('healthy','degraded','unhealthy','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'healthy',
  `services_status` json NOT NULL COMMENT 'Status of each service: ollama, database, redis, etc.',
  `errors_last_hour` int unsigned NOT NULL DEFAULT '0',
  `errors_last_day` int unsigned NOT NULL DEFAULT '0',
  `error_rate_per_hour` decimal(10,2) NOT NULL DEFAULT '0.00',
  `active_workflows` int unsigned NOT NULL DEFAULT '0',
  `running_workflows` int unsigned NOT NULL DEFAULT '0',
  `failed_workflows_24h` int unsigned NOT NULL DEFAULT '0',
  `avg_workflow_duration_ms` int unsigned DEFAULT NULL,
  `disk_free_gb` decimal(10,2) DEFAULT NULL,
  `memory_usage_mb` int unsigned DEFAULT NULL,
  `queue_size` int unsigned NOT NULL DEFAULT '0',
  `queue_worker_status` enum('running','stopped','unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `avg_response_time_ms` int unsigned DEFAULT NULL,
  `slow_queries_count` int unsigned NOT NULL DEFAULT '0',
  `alerts_generated` json DEFAULT NULL COMMENT 'Array of alert types triggered',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `system_health_snapshots_created_at_index` (`created_at`),
  KEY `system_health_snapshots_health_score_index` (`health_score`),
  KEY `idx_health_snapshots_created_at` (`created_at`),
  KEY `idx_health_snapshots_status_time` (`health_status`,`created_at`),
  KEY `idx_health_snapshots_score` (`health_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_issues` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggested_fix` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `finding_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `detected_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL,
  `detected_at` timestamp NOT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `occurrence_count` int unsigned NOT NULL DEFAULT '1',
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_issues_status_severity_index` (`status`,`severity`),
  KEY `system_issues_detected_at_index` (`detected_at`),
  KEY `system_issues_category_index` (`category`),
  KEY `idx_pending_issues` (`status`,`severity`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `node_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `output` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ttl_seconds` int unsigned NOT NULL DEFAULT '3600',
  `expires_at` timestamp NULL DEFAULT NULL,
  `workflow_id` int unsigned DEFAULT NULL,
  `hit_count` int unsigned NOT NULL DEFAULT '0',
  `last_hit_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cache_key` (`cache_key`),
  KEY `idx_node_type` (`node_type`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_workflow_id` (`workflow_id`),
  KEY `idx_input_hash` (`input_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_cache_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_cache_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `node_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stat_type` enum('hit','miss') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` int unsigned NOT NULL DEFAULT '0',
  `recorded_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_node_stat_date` (`node_type`,`stat_type`,`recorded_date`),
  KEY `idx_recorded_date` (`recorded_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_custody_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_custody_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `surface` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `surface_ref` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `acquired_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `outcome` enum('success','failure','cancel') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result_envelope` json DEFAULT NULL,
  `notification_state` enum('pending','delivered','suppressed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `progress_note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `active_key` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`released_at` is null) then concat(`surface`,_utf8mb4':',`surface_ref`) else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_custody_active` (`active_key`),
  KEY `task_custody_records_surface_surface_ref_index` (`surface`,`surface_ref`),
  KEY `task_custody_records_released_at_expires_at_index` (`released_at`,`expires_at`),
  KEY `task_custody_records_notification_state_index` (`notification_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhook_trigger_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_trigger_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `trigger_id` int unsigned NOT NULL,
  `request_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_headers` json DEFAULT NULL,
  `request_body` json DEFAULT NULL,
  `workflow_run_id` int unsigned DEFAULT NULL,
  `status` enum('success','rejected','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `response_time_ms` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_webhook_trigger_logs_trigger_id` (`trigger_id`),
  KEY `idx_webhook_trigger_logs_status` (`status`),
  KEY `idx_webhook_trigger_logs_created_at` (`created_at`),
  CONSTRAINT `fk_webhook_trigger_logs_trigger` FOREIGN KEY (`trigger_id`) REFERENCES `webhook_triggers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhook_triggers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_triggers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int unsigned NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `secret_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `allowed_ips` json DEFAULT NULL,
  `input_schema` json DEFAULT NULL,
  `last_triggered_at` timestamp NULL DEFAULT NULL,
  `trigger_count` int unsigned DEFAULT '0',
  `rate_limit` int unsigned DEFAULT '60' COMMENT 'Max requests per minute',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_webhook_triggers_workflow_id` (`workflow_id`),
  KEY `idx_webhook_triggers_token` (`token`),
  KEY `idx_webhook_triggers_is_active` (`is_active`),
  CONSTRAINT `fk_webhook_triggers_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `windows_sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `windows_sync_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `started_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` enum('running','completed','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `files_scanned` int NOT NULL DEFAULT '0',
  `files_new` int NOT NULL DEFAULT '0',
  `files_updated` int NOT NULL DEFAULT '0',
  `files_skipped` int NOT NULL DEFAULT '0',
  `files_error` int NOT NULL DEFAULT '0',
  `notes_created` int NOT NULL DEFAULT '0',
  `notes_updated` int NOT NULL DEFAULT '0',
  `folders_processed` json DEFAULT NULL,
  `error_log` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `windows_sync_runs_status_index` (`status`),
  KEY `windows_sync_runs_started_at_index` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_approval_gates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_approval_gates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workflow_run_id` int unsigned NOT NULL,
  `node_execution_id` bigint unsigned DEFAULT NULL,
  `approval_type` enum('manual','condition') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `status` enum('pending','approved','rejected','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` timestamp NULL DEFAULT NULL,
  `responded_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timeout_minutes` int unsigned NOT NULL DEFAULT '1440',
  `context` json DEFAULT NULL,
  `response_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_gates_run` (`workflow_run_id`),
  KEY `idx_gates_status` (`status`),
  KEY `idx_gates_requested` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_approval_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_approval_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int unsigned NOT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` int unsigned DEFAULT NULL,
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_approval_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_approval_notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int unsigned NOT NULL,
  `approver_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_escalation` tinyint(1) DEFAULT '0',
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_approval_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_approval_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `workflow_run_id` int unsigned NOT NULL,
  `node_id` int unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `data_snapshot` json DEFAULT NULL,
  `approvers` json DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `status` enum('pending','approved','rejected','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `escalation_level` int unsigned DEFAULT '0',
  `decided_by` int unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_run` (`workflow_run_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_backups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int unsigned NOT NULL,
  `backup_data` json NOT NULL COMMENT 'Complete workflow snapshot: nodes, configs, defaults',
  `backup_type` enum('auto','manual','pre_edit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL COMMENT 'User ID who triggered backup, NULL for auto',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_workflow_id` (`workflow_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_backup_type` (`backup_type`),
  KEY `fk_workflow_backups_user` (`created_by`),
  CONSTRAINT `fk_workflow_backups_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_workflow_backups_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auto-backup system for workflow editor - stores complete workflow snapshots';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_connections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` bigint unsigned NOT NULL,
  `source_node_id` bigint unsigned NOT NULL,
  `target_node_id` bigint unsigned NOT NULL,
  `source_port` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'output',
  `target_port` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'input',
  `condition_expression` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wc_workflow` (`workflow_id`),
  KEY `idx_wc_source` (`source_node_id`),
  KEY `idx_wc_target` (`target_node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_defaults`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_defaults` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int unsigned NOT NULL,
  `config_key` varchar(255) NOT NULL,
  `config_value` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_workflow_default` (`workflow_id`,`config_key`),
  CONSTRAINT `workflow_defaults_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_diagnostics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_diagnostics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int unsigned NOT NULL COMMENT 'Foreign key to workflows table',
  `workflow_run_id` int unsigned DEFAULT NULL COMMENT 'Most recent run ID',
  `total_runs` int unsigned NOT NULL DEFAULT '0',
  `successful_runs` int unsigned NOT NULL DEFAULT '0',
  `failed_runs` int unsigned NOT NULL DEFAULT '0',
  `avg_duration_ms` int unsigned DEFAULT NULL,
  `most_common_error` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_frequency` json DEFAULT NULL COMMENT 'Map of error types to counts',
  `failing_nodes` json DEFAULT NULL COMMENT 'Array of problematic node IDs',
  `success_rate` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '0-100%',
  `health_status` enum('healthy','degraded','failing','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'healthy',
  `last_failure_at` timestamp NULL DEFAULT NULL,
  `consecutive_failures` int unsigned NOT NULL DEFAULT '0',
  `last_analysis_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_diagnostics_health_status_index` (`health_status`),
  KEY `workflow_diagnostics_success_rate_index` (`success_rate`),
  KEY `workflow_diagnostics_last_failure_at_index` (`last_failure_at`),
  KEY `workflow_diagnostics_consecutive_failures_index` (`consecutive_failures`),
  KEY `idx_workflow_diagnostics_created_at` (`created_at`),
  KEY `idx_workflow_diagnostics_success_rate` (`success_rate`),
  KEY `idx_workflow_diagnostics_workflow_time` (`workflow_id`,`created_at`),
  CONSTRAINT `fk_workflow_diagnostics_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `execution_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'UUID linking events to a single workflow execution',
  `sequence` int unsigned NOT NULL COMMENT 'Monotonically increasing event number per execution',
  `event_type` enum('NodeStarted','NodeCompleted','NodeFailed','SignalReceived','VariableSet','CompensationStarted','CompensationCompleted','CompensationFailed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `node_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Node identifier (workflow_node.id or custom)',
  `payload` json DEFAULT NULL COMMENT 'Event-specific data (input, output, error, etc.)',
  `metadata` json DEFAULT NULL COMMENT 'Contextual info (duration_ms, attempt, user, etc.)',
  `recorded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_execution_sequence` (`execution_id`,`sequence`),
  KEY `idx_execution_node` (`execution_id`,`node_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_recorded_at` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_execution_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_execution_metrics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workflow_run_id` int unsigned NOT NULL,
  `node_execution_id` bigint unsigned DEFAULT NULL,
  `node_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metric_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_value` decimal(12,4) NOT NULL,
  `unit` enum('ms','bytes','count','percent') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ms',
  `recorded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_metrics_run` (`workflow_run_id`),
  KEY `idx_metrics_node_type` (`node_type`),
  KEY `idx_metrics_name` (`metric_name`),
  KEY `idx_metrics_recorded` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_node_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_node_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `node_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cache_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cached_output` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hits` int unsigned NOT NULL DEFAULT '0',
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_node_cache` (`node_type`,`cache_key`),
  KEY `idx_node_cache_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_node_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_node_configs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `workflow_node_id` int unsigned NOT NULL,
  `config_key` varchar(255) NOT NULL,
  `config_value` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_node_config` (`workflow_node_id`,`config_key`),
  CONSTRAINT `workflow_node_configs_ibfk_1` FOREIGN KEY (`workflow_node_id`) REFERENCES `workflow_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_node_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_node_metrics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `workflow_run_id` int unsigned NOT NULL,
  `workflow_node_id` int unsigned DEFAULT NULL,
  `node_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_ms` decimal(10,2) DEFAULT NULL,
  `memory_bytes` bigint unsigned DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '1',
  `error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `executed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wnm_run` (`workflow_run_id`),
  KEY `idx_wnm_node` (`workflow_node_id`),
  KEY `idx_wnm_type` (`node_type`),
  KEY `idx_wnm_executed` (`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_nodes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int unsigned NOT NULL,
  `node_type` varchar(255) NOT NULL,
  `node_order` int unsigned NOT NULL,
  `timeout_seconds` int unsigned DEFAULT NULL COMMENT 'Node execution timeout in seconds (NULL = use default)',
  `compensation_handler` varchar(255) DEFAULT NULL COMMENT 'Compensation handler class or method for rollback',
  `compensation_config` json DEFAULT NULL COMMENT 'Configuration for the compensation handler',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_workflow_order` (`workflow_id`,`node_order`),
  CONSTRAINT `workflow_nodes_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_run_inputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_inputs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int unsigned NOT NULL,
  `input_key` varchar(255) DEFAULT NULL,
  `input_value` mediumtext,
  PRIMARY KEY (`id`),
  KEY `run_id` (`run_id`),
  CONSTRAINT `workflow_run_inputs_ibfk_1` FOREIGN KEY (`run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_run_outputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_run_outputs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int unsigned NOT NULL,
  `output_key` varchar(255) DEFAULT NULL,
  `output_value` mediumtext,
  PRIMARY KEY (`id`),
  KEY `run_id` (`run_id`),
  KEY `idx_run_id` (`run_id`),
  CONSTRAINT `workflow_run_outputs_ibfk_1` FOREIGN KEY (`run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_runs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int unsigned NOT NULL,
  `status` enum('running','completed','failed') NOT NULL,
  `error_message` text,
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `parent_run_id` int unsigned DEFAULT NULL,
  `parent_node_execution_id` int unsigned DEFAULT NULL,
  `depth` tinyint unsigned DEFAULT '0',
  `idempotency_key` varchar(64) DEFAULT NULL COMMENT 'SHA256 hash of workflow_id + normalized input for dedup',
  `total_duration_ms` decimal(10,2) DEFAULT NULL,
  `nodes_executed` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_idempotency_key` (`idempotency_key`),
  KEY `idx_workflow_status` (`workflow_id`,`status`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_workflow_runs_composite` (`workflow_id`,`status`,`started_at`),
  KEY `idx_workflow_runs_status_completed` (`status`,`completed_at`),
  KEY `idx_parent_run` (`parent_run_id`),
  KEY `idx_depth` (`depth`),
  KEY `idx_workflow_idempotency` (`workflow_id`,`idempotency_key`),
  CONSTRAINT `fk_parent_run` FOREIGN KEY (`parent_run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workflow_runs_ibfk_1` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_definition` json NOT NULL,
  `template_nodes` json NOT NULL,
  `default_config` json DEFAULT NULL,
  `usage_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_templates_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflow_variables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_variables` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int unsigned NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_workflow` (`workflow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflows` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `schedule` varchar(255) DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `current_version` int unsigned DEFAULT '1',
  `error_handling` enum('stop','continue') DEFAULT 'stop',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_active_schedule` (`active`,`schedule`),
  KEY `idx_workflows_active_schedule` (`active`,`schedule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `youtube_playlist_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `youtube_playlist_progress` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `playlist_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_video_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_video_added_at` timestamp NULL DEFAULT NULL,
  `videos_processed_count` int NOT NULL DEFAULT '0',
  `last_run_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `youtube_playlist_progress_playlist_id_unique` (`playlist_id`),
  KEY `youtube_playlist_progress_playlist_id_index` (`playlist_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `youtube_transcripts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `youtube_transcripts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `video_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'YouTube video ID (11 chars typical)',
  `language` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en' COMMENT 'Language code (en, es, fr, etc.)',
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Full transcript text (concatenated)',
  `timed_content` json DEFAULT NULL COMMENT 'Timestamped segments [{start, duration, text}]',
  `source_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Extraction method: direct_api, invidious, piped, library, yt-dlp',
  `duration_seconds` int unsigned DEFAULT NULL COMMENT 'Video duration in seconds',
  `word_count` int unsigned DEFAULT NULL COMMENT 'Total word count',
  `fetched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When transcript was fetched',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `rag_indexed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_video_language` (`video_id`,`language`),
  KEY `idx_language` (`language`),
  KEY `idx_fetched_at` (`fetched_at`),
  KEY `idx_source_method` (`source_method`),
  KEY `youtube_transcripts_rag_indexed_at_index` (`rag_indexed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (240,'2026_01_19_000001_baseline_existing_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (241,'2026_01_19_000002_create_folder_semantics_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (242,'2026_01_21_100001_create_discovery_rules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (243,'2026_01_21_100002_create_source_performance_feedback_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (244,'2026_01_21_100003_create_source_discovery_patterns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (245,'2026_01_21_100004_seed_discovery_rules',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (252,'2026_01_22_000001_create_genealogy_research_fact_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (253,'2026_01_22_160000_add_nextcloud_modified_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (254,'2026_01_23_165646_create_file_registry_folder_status_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (255,'2026_01_24_120600_add_metadata_to_chat_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (256,'2026_01_25_091043_add_ai_quality_scoring_to_research_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (257,'2026_01_31_000001_remove_windows_file_organizer_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (258,'2026_01_31_000002_add_rag_indexed_at_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (259,'2026_01_31_000003_add_catalog_columns_to_folder_semantics',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (260,'2026_01_31_000004_update_file_catalog_scheduled_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (261,'2026_02_01_000001_create_ollama_models_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (262,'2026_02_01_000002_create_llm_instances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (263,'2026_02_01_000003_add_instance_id_to_ollama_models',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (264,'2026_02_01_144905_drop_research_mission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (265,'2026_02_01_145653_add_deduplication_to_research_results',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (266,'2026_02_03_000001_create_email_bounce_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (267,'2026_02_03_000002_create_youtube_chapters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (268,'2026_02_03_000003_create_workflow_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (269,'2026_02_03_000004_create_dead_letter_queue_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (270,'2026_02_03_000006_create_perceptual_hash_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (271,'2026_02_03_000007_add_fan_out_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (272,'2026_02_03_000008_create_genealogy_research_tasks_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (273,'2026_02_03_000009_add_email_threading',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (274,'2026_02_03_000009_create_source_credibility_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (275,'2026_02_03_000010_add_workflow_versioning',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (276,'2026_02_03_000011_add_subworkflow_support',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (277,'2026_02_03_000012_create_webhook_triggers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (278,'2026_02_03_000013_create_compensation_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (279,'2026_02_03_131241_add_parent_id_to_rag_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (280,'2026_02_03_141348_add_context_columns_to_rag_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (281,'2026_02_03_150000_create_task_cache_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (282,'2026_02_03_180519_create_guardrail_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (283,'2026_02_03_190000_create_human_review_tasks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (284,'2026_02_03_200000_add_evidence_classification_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (285,'2026_02_03_200000_add_source_classification_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (286,'2026_02_03_200001_create_evidence_correlations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (287,'2026_02_03_210000_create_agent_handoffs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (288,'2026_02_03_210000_create_distributed_agents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (289,'2026_02_03_210000_create_prompt_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (290,'2026_02_03_220000_create_agent_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (291,'2026_02_04_000001_create_youtube_transcripts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (292,'2026_02_04_000003_add_gedcom7_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (293,'2026_02_04_000004_create_email_rate_limits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (294,'2026_02_04_000005_create_video_hash_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (295,'2026_02_04_000006_add_ai_tagging_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (296,'2026_02_04_100001_create_genealogy_dna_kits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (297,'2026_02_04_100002_create_genealogy_dna_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (298,'2026_02_04_100003_create_genealogy_dna_segments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (299,'2026_02_04_100004_create_genealogy_dna_triangulation_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (300,'2026_02_04_110000_add_email_sentiment_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (301,'2026_02_04_110001_create_email_unsubscribe_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (302,'2026_02_04_120000_create_email_follow_ups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (303,'2026_02_04_130000_create_genealogy_fan_cluster_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (304,'2026_02_04_140000_add_workflow_idempotency',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (305,'2026_02_04_140001_create_sender_profiles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (306,'2026_02_04_140002_add_node_timeout_seconds',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (307,'2026_02_05_000001_create_genealogy_places_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (308,'2026_02_05_000002_add_chunk_hashes_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (309,'2026_02_05_100001_create_raptor_summary_hierarchy_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (310,'2026_02_05_100002_add_compensation_handler_to_workflow_nodes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (311,'2026_02_05_100003_create_genealogy_change_history_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (312,'2026_02_05_120000_add_place_ids_to_genealogy_persons',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (313,'2026_02_05_130611_add_rag_processing_columns_to_rag_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (314,'2026_02_06_200000_create_genealogy_media_scan_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (315,'2026_02_07_000001_extend_research_hints_for_records',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (316,'2026_02_07_000003_create_file_organization_rules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (317,'2026_02_07_000004_add_thumbnail_columns_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (318,'2026_02_07_000005_add_email_enhancements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (319,'2026_02_07_000006_add_file_management_enhancements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (320,'2026_02_07_000007_add_data_removal_enhancements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (321,'2026_02_07_000008_add_workflow_enhancements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (322,'2026_02_07_000009_add_genealogy_enhancements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (323,'2026_02_07_010000_add_unique_index_to_media_scan_log',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (324,'2026_02_10_210000_add_breach_monitoring_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (325,'2026_02_10_210001_add_face_region_integration',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (326,'2026_02_13_000001_add_enhancement_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (327,'2026_02_13_000001_create_enhancement_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (328,'2026_02_13_150000_create_embedding_training_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (329,'2026_02_13_160000_create_enhancement_n04_n16_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (330,'2026_02_15_000001_add_youtube_postprocess_nodes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (331,'2026_02_15_000002_raise_processing_limits_for_local_nextcloud',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (332,'2026_02_15_000003_fix_catalog_sync_and_optimize_schedules',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (333,'2026_02_16_075233_create_calendar_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (334,'2026_02_16_075234_create_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (335,'2026_02_16_075235_create_news_articles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (336,'2026_02_16_082500_add_rag_indexed_at_to_domain_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (337,'2026_02_16_140000_add_media_face_sync_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (338,'2026_02_17_193700_fix_perceptual_hash_dhash_column_size',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (339,'2026_02_18_100000_add_date_taken_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (340,'2026_02_18_133244_add_exif_writeback_tracking_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (341,'2026_02_19_100000_add_genealogy_media_validate_scheduled_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (342,'2026_02_20_120000_create_agent_memory_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (343,'2026_02_20_140000_add_genealogy_agent_scheduled_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (344,'2026_02_20_141818_add_current_path_index_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (345,'2026_02_20_160000_add_phase3_agent_scheduled_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (346,'2026_02_20_170000_create_agent_review_queue_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (347,'2026_02_20_180000_create_agent_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (348,'2026_02_20_190000_add_agent_cleanup_scheduled_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (349,'2026_02_20_200000_create_extension_browse_queue_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (350,'2026_02_21_000001_boost_rag_indexing_throughput',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (351,'2026_02_21_000002_add_pipeline_monitor_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (352,'2026_02_22_063600_add_ai_ops_agent_scheduled_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (353,'2026_02_22_070000_create_agent_tool_registry_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (354,'2026_02_22_080000_add_pushover_receipt_poll_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (355,'2026_02_22_090000_create_llm_model_profiles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (356,'2026_02_22_100000_add_external_llm_providers_and_genealogy_research_providers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (357,'2026_02_22_110000_add_api_key_columns_to_provider_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (358,'2026_02_22_120000_seed_nara_and_research_sources',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (359,'2026_02_22_130000_create_domain_credibility_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (360,'2026_02_23_180000_add_agent_health_check_tool',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (361,'2026_02_24_100000_add_pid_tracking_to_scheduled_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (362,'2026_02_24_100001_add_parallel_worker_support',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (363,'2026_02_24_110000_raise_max_parallel_safety_caps',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (364,'2026_02_24_120000_add_code_quality_check_tool',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (365,'2026_02_24_150000_create_genealogy_proposed_relationships_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (366,'2026_02_24_151000_fix_archive_tool_registrations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (367,'2026_02_25_095522_add_email_ops_agent_tools_and_schedule',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (368,'2026_02_25_100000_add_safety_columns_to_agent_tool_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (369,'2026_02_25_110000_register_mcp_bridge_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (370,'2026_02_25_120000_create_review_type_registry_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (371,'2026_02_25_120000_seed_tool_risk_classifications',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (372,'2026_02_25_130000_register_genealogy_research_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (373,'2026_02_25_140000_fix_hint_status_tool_description',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (374,'2026_02_25_150000_add_ui_schema_to_review_type_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (375,'2026_02_25_160000_add_context_length_to_llm_instances',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (376,'2026_02_25_170000_fix_agent_cleanup_scheduled_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (377,'2026_02_25_180000_add_file_ops_agent_tools_and_schedule',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (378,'2026_02_25_200000_add_research_ops_agent_tools_and_schedule',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (379,'2026_02_25_200000_register_apply_workflow_proposals_tool',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (380,'2026_02_25_220000_add_workflow_ops_agent_tools_and_schedule',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (381,'2026_02_25_230000_add_youtube_ops_agent_and_gpu_to_file_ops',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (382,'2026_02_25_235000_fix_unqualified_service_class_names',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (383,'2026_02_26_000000_activate_agent_handoff_system',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (384,'2026_02_26_000000_add_factcheck_and_data_removal_ops_agents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (385,'2026_02_26_010000_add_file_curator_agent_tools_and_schedule',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (386,'2026_02_26_020000_add_research_analyst_agent_tools_and_schedule',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (387,'2026_02_26_030000_create_research_engine_health_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (388,'2026_02_26_040000_fix_review_queue_spam_ignore_and_json',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (389,'2026_02_26_050000_create_skill_versions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (390,'2026_02_26_060000_create_agent_benchmarks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (391,'2026_02_26_120000_add_ignored_status_to_removal_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (392,'2026_02_26_120000_add_tool_proposal_review_type_and_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (393,'2026_02_26_140303_add_rename_action_to_face_review_type',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (394,'2026_02_26_160000_add_procedural_memory_columns_and_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (395,'2026_02_26_165547_update_agent_cleanup_job_to_include_sessions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (396,'2026_02_27_060000_add_skill_optimization_review_type_and_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (397,'2026_02_27_120000_add_tool_composition_system',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (398,'2026_02_27_180000_fix_nara_and_tool_phase_gaps',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (399,'2026_02_28_010000_add_knowledge_graph_build_scheduled_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (400,'2026_02_28_030000_register_graph_search_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (401,'2026_02_28_040000_update_rag_deep_search_graph_params',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (402,'2026_02_28_060000_create_speculative_executions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (403,'2026_02_28_060001_add_speculative_to_agent_benchmarks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (404,'2026_02_28_060002_register_speculative_execution_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (405,'2026_02_28_120000_create_adaptive_mode_selections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (406,'2026_02_28_120000_expand_agent_summary_columns_to_mediumtext',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (407,'2026_02_28_130000_fix_privacy_review_type_fetch_sql',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (408,'2026_02_28_140000_add_faces_page_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (409,'2026_02_28_150000_add_cluster_to_faces',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (410,'2026_02_28_160001_add_recluster_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (411,'2026_02_28_160002_add_nightly_ops_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (412,'2026_02_28_170000_register_nara_download_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (413,'2026_02_28_200000_add_log_analyst_agent',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (414,'2026_02_28_200000_create_agent_episode_summaries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (415,'2026_02_28_210000_fix_log_analyst_found_bugs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (416,'2026_02_28_220000_register_internet_archive_search_tool',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (417,'2026_02_28_230000_add_raptor_and_community_detection_scheduled_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (418,'2026_02_28_240000_register_graph_management_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (419,'2026_02_28_250000_create_mcp_tool_calls_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (420,'2026_02_28_300001_register_graph_quality_metrics_tool',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (421,'2026_02_28_300002_schedule_sentence_indexing_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (422,'2026_02_28_400001_add_entity_resolution_scheduled_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (423,'2026_02_28_400002_register_entity_resolution_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (424,'2026_03_01_020000_register_temporal_kg_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (425,'2026_03_01_030000_register_probe_unhealthy_providers_tool',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (426,'2026_03_01_120000_create_genealogy_proposed_changes_and_review_type',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (427,'2026_03_01_120000_update_scheduled_job_timeouts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (428,'2026_03_01_130000_add_dynamic_batch_sizing_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (429,'2026_03_03_120000_add_embedding_config_to_llm_instances',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (430,'2026_03_03_130000_register_genealogy_finding_review_type',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (431,'2026_03_03_140000_fix_change_proposal_review_card_schema',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (432,'2026_03_03_150000_fix_genealogy_finding_card_color',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (433,'2026_03_03_200000_add_role_model_config_to_llm_instances',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (434,'2026_03_03_210000_register_check_model_updates_tool',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (435,'2026_03_03_220000_schedule_check_model_updates_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (436,'2026_03_04_100000_fix_post_agent_message_body_required',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (437,'2026_03_04_200000_n58_n59_review_queue_cleanup_and_enrich',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (438,'2026_03_04_210000_n61_n54_extend_genealogy',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (439,'2026_03_04_220000_fix_privacy_review_fetch_sql',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (440,'2026_03_05_100000_n68_remove_hardcoded_limit_from_review_fetch_sql',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (441,'2026_03_05_200001_fix_face_recluster_full_command',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (442,'2026_03_05_210001_add_stall_exempt_to_scheduled_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (443,'2026_03_05_220001_add_raptor_error_count_to_rag_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (444,'2026_03_06_090001_add_knowledge_graph_build_stall_exempt',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (445,'2026_03_06_100001_add_raptor_eligible_to_rag_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (446,'2026_03_06_100002_update_raptor_scheduled_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (447,'2026_03_06_N80_update_se_batch_size',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (448,'2026_03_06_N81_add_se_eligible_to_rag_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (449,'2026_03_06_N85_fix_genealogy_review_cascade',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (450,'2026_03_07_N102_htr_transcription',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (451,'2026_03_07_N103_familysearch_hint_sync',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (452,'2026_03_07_N88_register_genealogy_landscape_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (453,'2026_03_07_N89b_register_coverage_tools_and_job',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (454,'2026_03_07_N89_genealogy_ancestor_paths_and_coverage',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (455,'2026_03_07_N90_add_root_person_to_trees',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (456,'2026_03_07_N91_relationship_proposal_enhancements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (457,'2026_03_07_N92_N101_N104_genealogy_upgrades',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (458,'2026_03_07_N93_fan_cooccurrences',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (459,'2026_03_07_N95_source_conflicts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (460,'2026_03_07_N96_gps_proof_generator',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (461,'2026_03_07_N97_search_coverage',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (462,'2026_03_07_N99_N100_graph_dedup_repo_routing',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (463,'2026_03_08_000000_N106_compute_router',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (464,'2026_03_08_100000_N106b_compute_shares_gpu_column',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (465,'2026_03_08_200000_add_gps_and_exif_fields_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (466,'2026_03_08_200000_N107_audit_fixes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (467,'2026_03_08_210000_add_exif_location_written_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (468,'2026_03_09_100000_add_scan_detection_to_file_registry',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (469,'2026_03_10_000001_add_wikitree_and_public_search_providers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (470,'2026_03_10_000002_n114_genealogy_external_sources_and_metrics',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (471,'2026_03_10_000003_n115_register_missing_genealogy_agent_tools',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (472,'2026_03_10_000004_n115b_genealogy_agent_schedule_and_sql_fixes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (473,'2026_03_10_000005_n115c_fix_agent_tool_registry_fan_cooccurrence',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (474,'2026_03_11_000001_consolidate_daily_report',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (475,'2026_03_11_000002_fix_research_facts_constraint_and_fuzzystrmatch',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (476,'2026_03_11_000003_add_composed_to_agent_tool_registry_source_enum',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (477,'2026_03_12_000001_wire_genealogy_finding_approve_method',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (478,'2026_03_12_000002_create_phantom_tables',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (479,'2026_03_13_000001_n125_review_queue_quality_gates',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (480,'2026_03_13_000002_drop_dormant_service_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (481,'2026_03_13_000003_add_ignore_sql_to_review_type_registry',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (482,'2026_03_13_000004_fix_agent_review_type_filter_whitelist',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (483,'2026_03_14_000001_fix_video_mime_types',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (484,'2026_03_16_000001_D1_D2_drop_email_ops_and_data_removal_ops_tables',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (485,'2026_03_16_000002_D1_D2_remove_dead_agent_tools',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (486,'2026_03_16_000003_SC3_promote_hardcoded_constants_to_system_configs',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (487,'2026_03_16_000004_DI1_add_calendar_sync_scheduled_job',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (488,'2026_03_16_000005_DI1_reset_stale_calendar_rag_indexed',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (489,'2026_03_17_000001_DI2_add_contacts_sync_scheduled_job',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (490,'2026_03_17_000002_repurpose_agent_execution_log_as_audit',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (491,'2026_03_17_000003_drop_dead_folder_organization_tables',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (492,'2026_03_18_000001_AI1_create_llm_cascade_log',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (493,'2026_03_18_000002_N140_add_enrichment_status_to_genealogy_media',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (494,'2026_03_18_000003_AG3_create_agent_memory_links',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (495,'2026_03_18_000004_AG4_add_is_shared_to_agent_procedures',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (496,'2026_03_18_000005_RAG4_create_rag_chunk_hypotheticals',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (497,'2026_03_18_000010_GR5_add_kg_content_hash_to_rag_documents',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (498,'2026_03_18_000011_GR6_add_kg_community_id_to_raptor_summaries',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (499,'2026_03_18_000012_GR11_create_knowledge_graph_hyperedges',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (500,'2026_03_19_000001_FC2_add_bayesian_columns_to_source_credibility',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (501,'2026_03_19_000002_GEN1_create_genealogy_source_registry',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (502,'2026_03_19_000003_INF10a_create_remediation_actions',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (503,'2026_03_19_000004_INF10e_add_finding_type_to_review_queue',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (504,'2026_03_19_000005_EM1_create_detected_bills',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (505,'2026_03_21_000006_create_rag_email_index',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (506,'2026_03_20_082748_cleanup_concatenated_face_names',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (507,'2026_03_21_000001_add_midday_digest_scheduled_job',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (508,'2026_03_21_000002_create_pipeline_metrics_snapshots',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (509,'2026_03_21_000003_add_ellis_island_blm_glo_providers',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (510,'2026_03_21_000004_add_rag_indexed_at_to_genealogy_places_sources',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (511,'2026_03_21_000005_add_strategy_insight_to_agent_procedures',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (512,'2026_03_21_000007_add_email_rag_index_scheduled_job',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (513,'2026_03_21_000008_register_vision_screenshot_tool',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (514,'2026_03_21_000009_register_multi_agent_debate_tool',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (515,'2026_03_22_000001_scheduled_jobs_comprehensive_audit',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (516,'2026_03_22_000002_fix_unsafe_scheduled_job_flags',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (517,'2026_03_22_000003_remove_obsolete_scheduled_jobs',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (518,'2026_03_22_000004_n51_retroactive_proposal_generation',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (519,'2026_03_22_000005_sb4_framework_currency_check',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (520,'2026_03_22_000006_nara_census_tool_and_skill_update',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (521,'2026_03_22_000007_sprint_propositions_semantic_memory',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (522,'2026_03_22_000008_add_censored_column_to_llm_models',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (523,'2026_03_22_100001_N143_N144_external_service_registry_and_nara',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (524,'2026_03_23_000001_sprint_fix_timeouts_and_gnews_cleanup',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (525,'2026_03_23_000002_sprint2_kg_gaps_and_fixes',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (526,'2026_03_24_000001_register_get_priority_persons_tool',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (527,'2026_03_25_000001_add_file_duplicate_resolve_job',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (528,'2026_03_26_000001_rlm_recursion_framework',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (529,'2026_03_26_000002_rlm_phase5_7_seed_configs',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (530,'2026_03_26_000003_rlm_auto_decompose_config',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (531,'2026_03_27_000001_fix_genealogy_agent_timeout',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (532,'2026_03_27_000002_fix_llm_failover_and_agent_schedules',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (533,'2026_03_28_000001_add_fulltext_index_to_file_registry',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (534,'2026_03_28_000002_register_file_rag_backfill_job',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (535,'2026_03_28_000003_add_rag_indexed_at_to_genealogy_media',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (536,'2026_03_28_000004_remove_dead_agent_review_type',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (538,'2026_03_29_000001_add_covering_index_agent_recursion_calls',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (539,'2026_03_30_000001_add_missing_indexes_post_restore',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (540,'2026_03_31_000001_mark_agent_jobs_stall_exempt',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (541,'2026_04_01_000001_increase_agent_job_timeouts',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (542,'2026_04_01_000002_drop_redundant_mysql_indexes',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (543,'2026_04_04_071706_add_news_workflow_output_limits',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (544,'2026_04_04_110000_mark_agent_task_jobs_stall_exempt',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (545,'2026_04_04_111000_restore_genealogy_assess_direct_mode',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (546,'2026_04_04_150000_raise_agent_task_timeout_floor',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (547,'2026_04_04_151000_disable_familysearch_agent_tools',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (548,'2026_04_04_160000_stabilize_non_agent_scheduled_jobs',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (549,'2026_04_04_170000_stabilize_research_and_knowledge_jobs',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (550,'2026_04_04_171000_raise_research_analyst_timeout',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (551,'2026_04_04_172000_stabilize_joplin_youtube_organize',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (552,'2026_04_04_173000_stabilize_news_workflows',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (553,'2026_04_05_061500_stabilize_file_knowledge_and_email_jobs',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (554,'2026_04_05_063500_restore_agent_task_timeout_floor',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (555,'2026_04_05_072000_disable_recursion_for_news_batch_processors',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (556,'2026_04_07_073000_split_community_detection_reports',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (557,'2026_04_07_124000_retire_joplin_sync_workflow',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (558,'2026_04_07_125000_tighten_joplin_sync_direct_limit',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (559,'2026_04_08_150000_add_finding_type_to_system_issues',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (560,'2026_04_09_180000_remove_dlq_tools_from_workflow_ops_registry',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (561,'2026_04_10_050000_drop_dead_letter_queue_table',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (562,'2026_04_10_180000_realign_research_runtime_budgets',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (563,'2026_04_11_120000_redesign_genealogy_queue_contract',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (564,'2026_04_11_141500_realign_stabilized_scheduled_jobs',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (565,'2026_04_11_143500_reassert_research_analyst_runtime_floor',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (566,'2026_04_11_145500_remove_legacy_research_run_job',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (567,'2026_04_11_184500_throttle_knowledge_graph_build_job',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (568,'2026_04_12_063500_stabilize_ollama_profiles_for_offline_mode',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (569,'2026_04_12_080000_prioritize_local_ollama_for_offline_mode',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (570,'2026_04_12_120000_repair_missing_agent_sessions_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (571,'2026_04_13_180000_create_genealogy_intake_runs_table',41);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (572,'2026_04_14_210000_add_link_existing_fields_to_genealogy_proposed_relationships',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (573,'2026_04_17_130548_add_compatibility_authority_to_llm_instances',43);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (575,'2026_04_17_134457_seed_routing_profile_configs',44);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (578,'2026_04_17_160000_add_runtime_metadata_to_scheduled_jobs',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (579,'2026_04_17_175500_add_pending_dedup_unique_to_agent_review_queue',46);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (580,'2026_04_18_100000_create_task_custody_records_table',47);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (581,'2026_04_19_120000_create_offline_audit_events_table',48);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (582,'2026_04_22_184200_create_expected_outputs_catalog_table',99);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (583,'2026_04_23_090000_add_ollama_drift_check_scheduled_job',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (584,'2026_04_23_091000_bump_research_analyst_agent_timeout',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (585,'2026_04_24_153000_ensure_agent_sessions_skill_version_column',102);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (586,'2026_04_24_160000_tune_knowledge_graph_catchup_budget',103);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (587,'2026_04_12_140500_split_routine_workflow_pushover_group',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (588,'2026_04_12_141500_split_youtube_success_pushover_group',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (589,'2026_04_12_190000_add_scheduler_synthetic_probe_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (590,'2026_04_15_120000_add_uncensored_profile_to_llm_model_profiles',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (591,'2026_04_16_073047_register_genealogy_merge_review_type',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (592,'2026_04_16_202238_seed_offline_mode_config',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (593,'2026_04_17_133522_backfill_ollama_compat_authority_by_url',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (594,'2026_04_20_151500_add_knowledge_graph_catchup_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (595,'2026_04_23_133000_reassert_research_analyst_timeout_90',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (596,'2026_04_24_103000_lock_research_analyst_timeout_at_90',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (597,'2026_04_24_120000_backfill_ollama_drift_check_runtime_metadata',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (598,'2026_04_24_121000_backfill_knowledge_graph_catchup_runtime_metadata',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (599,'2026_04_24_184500_add_ops_heavy_window_baseline_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (600,'2026_04_24_185452_retire_legacy_ops_host_baseline_jobs',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (601,'2026_04_24_185717_delete_legacy_ops_host_baseline_jobs',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (602,'2026_04_26_073000_stabilize_prod_scheduler_failures',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (603,'2026_04_26_190000_rename_ops_theme_tokens_in_review_registry',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (604,'2026_04_27_120000_remove_unworkable_genealogy_api_surfaces',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (605,'2026_04_27_130000_gate_manual_only_genealogy_sources',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (606,'2026_04_29_000001_register_genealogy_review_packet_review_type',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (607,'2026_04_29_120000_stabilize_genealogy_media_consolidate_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (608,'2026_04_29_230000_seed_awo_recording_config',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (609,'2026_04_30_000001_update_genealogy_review_packet_actions',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (610,'2026_04_30_120000_create_bias_rating_aliases_table',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (611,'2026_04_30_125834_seed_common_bias_source_aliases',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (612,'2026_04_30_130000_seed_news_bias_feed_aliases',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (613,'2026_04_30_183000_add_bias_data_refresh_scheduled_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (614,'2026_04_30_191500_seed_real_news_no_bullshit_bias_rating',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (615,'2026_04_30_202000_add_news_source_inventory_scheduled_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (616,'2026_04_30_213000_add_face_link_weekly_report_scheduled_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (617,'2026_04_30_221500_add_awo_replay_weekly_report_scheduled_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (618,'2026_05_01_000001_add_kg_provenance_columns_to_pipeline_snapshots',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (619,'2026_05_01_000002_add_kg_provenance_snapshot_scheduled_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (620,'2026_05_01_000003_add_scheduler_optimize_weekly_report_scheduled_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (621,'2026_05_01_110000_create_dev_agent_readiness_snapshots_table',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (622,'2026_05_01_230000_apply_scheduler_spacing_first_batch',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (623,'2026_05_02_180000_restore_enabled_agent_task_timeout_floor',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (624,'2026_05_03_200000_tighten_news_pushover_delivery_window',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (625,'2026_05_04_081800_repair_schema_drift_and_redundant_indexes',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (626,'2026_05_04_093000_restore_news_pushover_multipart_pacing',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (627,'2026_05_04_164500_ensure_news_pushover_routine_source_group',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (628,'2026_05_05_090000_increase_news_pushover_multipart_spacing',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (629,'2026_05_05_190000_enable_news_pushover_part_timestamps',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (630,'2026_05_06_184500_enable_news_pushover_part_headers',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (631,'2026_05_08_090000_cap_news_pushover_delivery_parts',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (632,'2026_05_09_093600_register_genealogy_evidence_asset_capture_review_type',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (633,'2026_05_09_143000_add_genealogy_media_enrichment_handoff_jobs',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (634,'2026_05_12_063000_schedule_genealogy_full_rag_reindex',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (635,'2026_05_12_071000_seed_genealogy_expert_procedures',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (636,'2026_05_12_073000_schedule_genealogy_health_and_media_rag_jobs',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (637,'2026_05_12_141500_schedule_genealogy_duplicate_and_export_jobs',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (638,'2026_05_12_161500_schedule_genealogy_media_review_jobs',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (639,'2026_05_12_173000_schedule_genealogy_evidence_score_job',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (640,'2026_05_12_184500_schedule_genealogy_all_rag_index',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (641,'2026_05_13_120000_add_provenance_json_to_genealogy_proposed_changes',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (642,'2026_05_14_090000_register_genealogy_mcp_capture_agent_tools',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (643,'2026_05_14_091000_register_genealogy_source_gap_memory_agent_tools',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (644,'2026_05_14_093000_register_genealogy_direct_evidence_capture_tool',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (645,'2026_05_15_190000_register_genealogy_research_memo_tools',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (646,'2026_05_16_064000_register_genealogy_source_media_backfill_tool_and_schedule',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (647,'2026_05_16_070000_update_genealogy_source_media_backfill_retry_and_schedule',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (648,'2026_05_17_093000_register_genealogy_coverage_rebuild_tool',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (649,'2026_05_17_094500_register_genealogy_research_task_create_tool',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (650,'2026_05_17_131500_register_genealogy_lesson_memory_tools',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (651,'2026_05_17_132500_register_genealogy_lesson_memory_context_tool',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (652,'2026_05_17_134000_register_genealogy_memory_backfill_batch',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (653,'2026_05_20_101500_register_genealogy_mcp_agent_tool_access',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (654,'2026_05_20_193500_schedule_agent_doctor_readiness_snapshot',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (655,'2026_05_21_090000_register_codex_exec_llm_provider',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (656,'2026_05_21_093000_enable_codex_exec_git_repo_check_skip',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (657,'2026_05_21_100000_remove_news_pushover_delivery_cap',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (658,'2026_05_23_160000_add_llm_provider_privacy_gate',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (659,'2026_05_25_100500_reseed_genealogy_external_service_registry',104);

--
-- Required lookup/config seed data
--

INSERT INTO `agent_procedures` (`id`, `agent_id`, `name`, `trigger_pattern`, `action_sequence`, `strategy_insight`, `procedure_type`, `source_session_id`, `is_canonical`, `is_shared`, `is_retired`, `success_rate`, `times_used`, `times_succeeded`, `last_used_at`, `created_at`, `updated_at`) VALUES (1,'genealogy-researcher','GPS identity resolution before fact proposals','genealogy identity matching nickname married name face label media person link duplicate spouse parent child uncertain subject match','[{\"tool\": \"recall_procedures\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"recall_episodes\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_person_full\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"surname_phonetic_matches\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_siblings\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"fan_get_cooccurrences\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"evidence_build_chain\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"detect_duplicates\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"submit_for_review\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}]','Resolve the subject identity before accepting any fact. Require name plus date, place, relationship, FAN, occupation, media context, or source-chain anchors; never link from name alone.','success','genealogy-expert-seed-2026-05-12',1,1,0,0.8000,5,4,'2026-05-25 14:11:40','2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `agent_procedures` (`id`, `agent_id`, `name`, `trigger_pattern`, `action_sequence`, `strategy_insight`, `procedure_type`, `source_session_id`, `is_canonical`, `is_shared`, `is_retired`, `success_rate`, `times_used`, `times_succeeded`, `last_used_at`, `created_at`, `updated_at`) VALUES (2,'genealogy-researcher','Source-backed fact update workflow','genealogy birth death burial place spouse parent child fact update found record source citation evidence confidence','[{\"tool\": \"get_repositories_for_person\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"source_search_all\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_person_sources\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"evidence_build_chain\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"assess_gps_compliance\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"detect_source_conflicts\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"propose_change\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"submit_for_review\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"save_procedure\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}]','A fact update needs a real source, source quality, information quality, evidence type, temporal fit, conflict check, and provenance. Weak but useful evidence becomes a proposal or research task.','success','genealogy-expert-seed-2026-05-12',1,1,0,0.8000,5,4,'2026-05-25 14:11:40','2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `agent_procedures` (`id`, `agent_id`, `name`, `trigger_pattern`, `action_sequence`, `strategy_insight`, `procedure_type`, `source_session_id`, `is_canonical`, `is_shared`, `is_retired`, `success_rate`, `times_used`, `times_succeeded`, `last_used_at`, `created_at`, `updated_at`) VALUES (3,'genealogy-researcher','Negative search coverage workflow','genealogy no records found empty search negative evidence exhausted repository coverage repeat search avoid duplicate research','[{\"tool\": \"get_recent_searches\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_search_coverage\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_repositories_for_person\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"source_search_all\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"log_research_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"update_search_coverage\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"update_hint_status\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"create_research_task\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}]','No-result searches are useful GPS evidence when logged with repository, query, date range, geography, and rationale. Do not submit negative results as fact proposals.','success','genealogy-expert-seed-2026-05-12',1,1,0,0.8000,5,4,'2026-05-25 14:11:40','2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `agent_procedures` (`id`, `agent_id`, `name`, `trigger_pattern`, `action_sequence`, `strategy_insight`, `procedure_type`, `source_session_id`, `is_canonical`, `is_shared`, `is_retired`, `success_rate`, `times_used`, `times_succeeded`, `last_used_at`, `created_at`, `updated_at`) VALUES (4,'genealogy-records','Primary-record jurisdiction sweep','genealogy records census vital military immigration naturalization land pension jurisdiction original record image download attach source','[{\"tool\": \"recall_procedures\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_repositories_for_person\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"source_search_all\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"nara_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"nara_get_objects\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"nara_download_best\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"nara_copy_to_tree\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"log_research_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"update_search_coverage\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}]','Route by era and jurisdiction first, search the highest-yield repository, prefer source images over indexes, and capture downloadable originals into the FT folder before relying on them.','success','genealogy-expert-seed-2026-05-12',1,1,0,0.8000,5,4,'2026-05-25 14:11:40','2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `agent_procedures` (`id`, `agent_id`, `name`, `trigger_pattern`, `action_sequence`, `strategy_insight`, `procedure_type`, `source_session_id`, `is_canonical`, `is_shared`, `is_retired`, `success_rate`, `times_used`, `times_succeeded`, `last_used_at`, `created_at`, `updated_at`) VALUES (5,'genealogy-newspapers','Newspaper identity anchor workflow','genealogy newspaper obituary article marriage birth legal notice ocr publication date named relatives identity anchor','[{\"tool\": \"recall_procedures\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_person_full\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"surname_phonetic_matches\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"newspaper_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"newspaper_search_obituaries\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"internet_archive_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"log_research_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"update_search_coverage\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"submit_for_review\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"save_procedure\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}]','Use publication date, place, named relatives, and article context as identity anchors. Treat OCR snippets as leads until corroborated by page image, publication metadata, and tree context.','success','genealogy-expert-seed-2026-05-12',1,1,0,0.8000,5,4,'2026-05-25 14:11:40','2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `agent_procedures` (`id`, `agent_id`, `name`, `trigger_pattern`, `action_sequence`, `strategy_insight`, `procedure_type`, `source_session_id`, `is_canonical`, `is_shared`, `is_retired`, `success_rate`, `times_used`, `times_succeeded`, `last_used_at`, `created_at`, `updated_at`) VALUES (6,'genealogy-web','Community profile lead extraction workflow','genealogy wikitree web search community profile fan graph rag source citations lead extraction identity match','[{\"tool\": \"recall_procedures\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_person_full\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"wikitree_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"wikitree_get_person\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"mcp_searxng_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"rag_search\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"fan_analyze_cluster\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"submit_for_review\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"save_procedure\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}]','Treat community profiles and web pages as leads. Extract their cited sources, verify identity with lifetime/place/relationship anchors, and route uncertain web claims to review.','success','genealogy-expert-seed-2026-05-12',1,1,0,0.8000,5,4,'2026-05-25 14:11:40','2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `agent_procedures` (`id`, `agent_id`, `name`, `trigger_pattern`, `action_sequence`, `strategy_insight`, `procedure_type`, `source_session_id`, `is_canonical`, `is_shared`, `is_retired`, `success_rate`, `times_used`, `times_succeeded`, `last_used_at`, `created_at`, `updated_at`) VALUES (7,'genealogy-analyst','Conflict-first GPS proof analysis','genealogy evidence conflict proof conclusion gps source disagreement birth death parent spouse place duplicate merge','[{\"tool\": \"recall_procedures\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_person_full\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_person_sources\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"evidence_build_chain\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"detect_source_conflicts\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_source_conflicts\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"generate_gps_proof\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"submit_for_review\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"save_procedure\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}]','Analyze the claim, source classes, subject identity, conflicts, and weakest required link before writing a proof or proposing a resolution.','success','genealogy-expert-seed-2026-05-12',1,1,0,0.8000,5,4,'2026-05-25 14:11:40','2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `agent_procedures` (`id`, `agent_id`, `name`, `trigger_pattern`, `action_sequence`, `strategy_insight`, `procedure_type`, `source_session_id`, `is_canonical`, `is_shared`, `is_retired`, `success_rate`, `times_used`, `times_succeeded`, `last_used_at`, `created_at`, `updated_at`) VALUES (8,'genealogy-assessor','Research queue triage by genealogical value','genealogy research queue priority missing data direct ancestor relationship blocker source conflict export readiness media unlinked','[{\"tool\": \"recall_procedures\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_research_landscape\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_recent_searches\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_missing_data_report\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_open_research_tasks\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_priority_persons\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"get_search_coverage\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"create_research_task\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}, {\"tool\": \"save_procedure\", \"params\": [], \"params_source\": \"active_person_or_task_context\"}]','Prioritize by genealogical value: direct ancestors, relationship blockers, conflicts, export readiness, and media that can prove facts. Avoid duplicate tasks by checking coverage and open work first.','success','genealogy-expert-seed-2026-05-12',1,1,0,0.8000,5,4,'2026-05-25 14:11:40','2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (1,'evidence_capture_plan','App\\Engine\\MCPRouter','callTool','Plan capture-ready genealogy review evidence media for FT-local storage. Read-only; use before review or execution.','{\"type\": \"object\", \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 50, \"description\": \"Maximum review rows to scan\"}, \"compact\": {\"type\": \"boolean\", \"default\": true, \"description\": \"Return count-only payload by default\"}, \"dry_run\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Return query posture without scanning rows\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Optional tree ID used to scope review packets\"}, \"eligible_only\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Only count capture-ready candidates\"}}}','Returns the genealogy MCP tool payload, including success/error status and any dry-run or write-audit receipt.','[\"genealogy:read\"]','read','genealogy',0,20,'genealogy','evidence_capture_plan',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for Genea agent intake/citation workflow; dry-run and confirmation are enforced by the MCP service and offline policy.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (2,'evidence_capture_review','App\\Engine\\MCPRouter','callTool','Materialize tree-scoped approval rows for evidence media capture. No downloads or canonical media writes.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 50, \"description\": \"Maximum review rows to scan\"}, \"compact\": {\"type\": \"boolean\", \"default\": true, \"description\": \"Return count-only payload by default\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required true when execute=true\"}, \"execute\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Create noncanonical approval rows when true\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to scope capture approval rows\"}, \"eligible_only\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Only materialize capture-ready candidates\"}}}','Returns the genealogy MCP tool payload, including success/error status and any dry-run or write-audit receipt.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','evidence_capture_review',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for Genea agent intake/citation workflow; dry-run and confirmation are enforced by the MCP service and offline policy.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (3,'evidence_capture_execute','App\\Engine\\MCPRouter','callTool','Preflight or execute already-approved tree-scoped evidence media capture into FT storage with optional genealogy linking.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 25, \"description\": \"Maximum approved capture rows to inspect\"}, \"compact\": {\"type\": \"boolean\", \"default\": true, \"description\": \"Return count-only payload by default\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID stamped on approved capture review rows\"}, \"max_bytes\": {\"type\": \"integer\", \"description\": \"Optional per-file download byte cap\"}, \"save_preflight\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Stamp noncanonical executor preflight details only\"}, \"execute_capture\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Download/save approved assets when confirmed\"}, \"confirm_download\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required for execute_capture\"}, \"confirm_storage_write\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required for execute_capture\"}, \"confirm_genealogy_link\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Create person/family/source media links when executing\"}, \"confirm_noncanonical_write\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required for save_preflight\"}}}','Returns the genealogy MCP tool payload, including success/error status and any dry-run or write-audit receipt.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','evidence_capture_execute',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for Genea agent intake/citation workflow; dry-run and confirmation are enforced by the MCP service and offline policy.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (4,'source_citation_link_apply','App\\Engine\\MCPRouter','callTool','Dry-run-first bounded source citation plus person/family source link creation for already-vetted same-tree evidence.','{\"type\": \"object\", \"required\": [\"tree_id\", \"source_id\", \"text\"], \"properties\": {\"page\": {\"type\": \"string\", \"description\": \"Optional page, URL section, or review locator\"}, \"text\": {\"type\": \"string\", \"description\": \"Required evidence text explaining exactly what the source supports\"}, \"actor\": {\"type\": \"string\", \"default\": \"genea-agent\", \"description\": \"Audit actor label\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required true when dry_run=false\"}, \"dry_run\": {\"type\": \"boolean\", \"default\": true, \"description\": \"Preview only when true\"}, \"quality\": {\"type\": \"integer\", \"description\": \"Optional citation quality 0-100\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns all targets\"}, \"media_id\": {\"type\": \"integer\", \"description\": \"Optional cited media row in the same tree\"}, \"fact_type\": {\"type\": \"string\", \"default\": \"person_source_context\", \"description\": \"Citation fact type\"}, \"source_id\": {\"type\": \"integer\", \"description\": \"Existing genealogy source ID in the same tree\"}, \"family_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}, \"description\": \"Optional family IDs to cite/link\"}, \"person_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}, \"description\": \"Optional person IDs to cite/link\"}, \"evidence_type\": {\"type\": \"string\", \"default\": \"direct\", \"description\": \"direct, indirect, or negative\"}, \"information_type\": {\"type\": \"string\", \"default\": \"secondary\", \"description\": \"primary, secondary, or indeterminate\"}}}','Returns the genealogy MCP tool payload, including success/error status and any dry-run or write-audit receipt.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','source_citation_link_apply',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for Genea agent intake/citation workflow; dry-run and confirmation are enforced by the MCP service and offline policy.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (5,'source_gap_decision_lookup','App\\Engine\\MCPRouter','callTool','Read compact genealogy source-gap decision memory so agents avoid repeating weak or collateral evidence reviews.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 50, \"description\": \"Maximum matches to return\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to search\"}, \"decision\": {\"type\": \"string\", \"default\": \"all\", \"description\": \"Optional decision filter or all\"}, \"person_id\": {\"type\": \"integer\", \"description\": \"Optional person ID to inspect\"}}}','Returns the genealogy MCP tool payload with dry-run/write-audit status where applicable.','[\"genealogy:read\"]','read','genealogy',0,30,'genealogy','source_gap_decision_lookup',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for Genea source-gap memory so agents can avoid repeated weak/collateral checks.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (6,'source_gap_decision_add','App\\Engine\\MCPRouter','callTool','Dry-run-first source-gap review memory writer for collateral-only, weak evidence, deferred, and external-research decisions.','{\"type\": \"object\", \"required\": [\"tree_id\", \"person_id\", \"decision\", \"reason\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-agent\", \"description\": \"Audit actor label\"}, \"reason\": {\"type\": \"string\", \"description\": \"Evidence review reason\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required true when dry_run=false\"}, \"dry_run\": {\"type\": \"boolean\", \"default\": true, \"description\": \"Preview only when true\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID this decision applies to\"}, \"decision\": {\"type\": \"string\", \"description\": \"Decision code\"}, \"person_id\": {\"type\": \"integer\", \"description\": \"Person ID this source-gap decision applies to\"}, \"confidence\": {\"type\": \"number\", \"default\": 0.8, \"description\": \"Memory confidence 0..1\"}, \"source_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}, \"description\": \"Optional related source IDs reviewed\"}}}','Returns the genealogy MCP tool payload with dry-run/write-audit status where applicable.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','source_gap_decision_add',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for Genea source-gap memory so agents can avoid repeated weak/collateral checks.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (7,'evidence_capture_direct','App\\Engine\\MCPRouter','callTool','Dry-run-first one-off capture of a vetted evidence URL into tree-scoped FT storage with optional source/person/family linking.','{\"type\": \"object\", \"required\": [\"tree_id\", \"url\"], \"properties\": {\"url\": {\"type\": \"string\", \"description\": \"Vetted http/https evidence asset URL to capture\"}, \"actor\": {\"type\": \"string\", \"default\": \"genea-agent\", \"description\": \"Audit actor label\"}, \"label\": {\"type\": \"string\", \"description\": \"Optional media title/filename label\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required true when dry_run=false\"}, \"dry_run\": {\"type\": \"boolean\", \"default\": true, \"description\": \"Preview only when true\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns all link targets\"}, \"family_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree family ID\"}, \"max_bytes\": {\"type\": \"integer\", \"description\": \"Optional per-file download byte cap\"}, \"person_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree person ID\"}, \"source_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree genealogy source ID\"}, \"asset_type\": {\"type\": \"string\", \"description\": \"Optional asset type hint\"}, \"content_type\": {\"type\": \"string\", \"description\": \"Optional MIME hint such as image/jp2\"}, \"confirm_download\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required true when dry_run=false\"}, \"confirm_storage_write\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required true when dry_run=false\"}, \"confirm_genealogy_link\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Create person/family/source media links when executing\"}}}','Returns a dry-run preview or an execution payload with saved media IDs, link scopes, and write-audit receipt.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','evidence_capture_direct',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for direct vetted Genea evidence media capture; service enforces dry-run and download/storage confirmation flags.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (8,'research_memo_save','App\\Engine\\MCPRouter','callTool','Dry-run-first Genea MCP tool to save a reviewed research memo inside the FT tree root, append target notes, and optionally record source-gap memory.','{\"type\": \"object\", \"required\": [\"tree_id\", \"title\", \"body\"], \"properties\": {\"body\": {\"type\": \"string\", \"description\": \"Reviewed research memo body\"}, \"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"title\": {\"type\": \"string\", \"description\": \"Short memo title\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns the memo\"}, \"family_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree family ID\"}, \"overwrite\": {\"type\": \"boolean\", \"default\": false}, \"person_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree person ID\"}, \"confidence\": {\"type\": \"number\", \"default\": 0.8}, \"source_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"notes_append\": {\"type\": \"string\", \"description\": \"Optional note appended to the target person/family record\"}, \"relative_path\": {\"type\": \"string\", \"description\": \"Optional safe .md path below the tree media root\"}, \"source_gap_reason\": {\"type\": \"string\", \"description\": \"Required when source_gap_decision is provided\"}, \"source_gap_decision\": {\"type\": \"string\", \"description\": \"Optional source-gap decision code for person_id\"}}}','Returns the planned or applied FT-local memo path, note update counts, and optional source-gap memory result.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','research_memo_save',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for safe Genea research memo persistence without ad hoc tinker filesystem writes.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (9,'family_duplicate_retire','App\\Engine\\MCPRouter','callTool','Dry-run-first Genea MCP tool to retire an isolated duplicate family row after strict same-spouse and no-reference checks.','{\"type\": \"object\", \"required\": [\"tree_id\", \"keep_family_id\", \"duplicate_family_id\", \"reason\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"reason\": {\"type\": \"string\", \"description\": \"Evidence/review reason for retiring the duplicate\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns both families\"}, \"keep_family_id\": {\"type\": \"integer\", \"description\": \"Canonical family ID to keep\"}, \"duplicate_family_id\": {\"type\": \"integer\", \"description\": \"Isolated duplicate family ID to delete\"}}}','Returns the planned or applied duplicate-family cleanup with dependent row counts.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','family_duplicate_retire',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for safe duplicate-family cleanup without raw DELETE commands.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (10,'person_source_link_retire','App\\Engine\\MCPRouter','callTool','Dry-run-first Genea MCP tool to retire invalid uncited person-source link rows after strict tree and citation checks.','{\"type\": \"object\", \"required\": [\"tree_id\", \"person_source_ids\", \"reason\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"reason\": {\"type\": \"string\", \"description\": \"Evidence/review reason for retiring these source links\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns the person-source links\"}, \"person_source_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}, \"description\": \"genealogy_person_sources IDs to retire, max 50\"}}}','Returns a planned or applied uncited person-source link cleanup with citation blocking checks.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','person_source_link_retire',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for safe person-source link cleanup without raw DELETE commands.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (11,'source_media_backfill','App\\Engine\\MCPRouter','callTool','Dry-run-first bounded backfill of URL-only genealogy sources into tree-scoped FT storage, using NARA API digital objects when available.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 25, \"description\": \"Maximum source rows per batch\"}, \"order\": {\"type\": \"string\", \"default\": \"oldest\", \"description\": \"oldest or newest\"}, \"since\": {\"type\": \"string\", \"default\": \"14d\", \"description\": \"Window to scan, e.g. 24h, 14d, all\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID whose URL-only sources should be backfilled\"}, \"max_bytes\": {\"type\": \"integer\"}, \"source_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"link_sources\": {\"type\": \"boolean\", \"default\": true}, \"retry_blocked\": {\"type\": \"boolean\", \"default\": false}, \"confirm_download\": {\"type\": \"boolean\", \"default\": false}, \"confirm_storage_write\": {\"type\": \"boolean\", \"default\": false}, \"nara_metadata_snapshot\": {\"type\": \"boolean\", \"default\": true}}}','Returns source capture batch counts, saved/reused media IDs, NARA API activity, and blockers.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','source_media_backfill',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for source URL media backfill; skips previously blocked failures by default unless retry_blocked is requested.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (12,'coverage_rebuild','App\\Engine\\MCPRouter','callTool','Dry-run-first rebuild of genealogy ancestor paths and person coverage for one tree.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"confirm\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Required true when dry_run=false\"}, \"dry_run\": {\"type\": \"boolean\", \"default\": true, \"description\": \"Preview status and write requirements only\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to rebuild\"}, \"root_person_id\": {\"type\": \"integer\", \"description\": \"Optional root person override; defaults to genealogy_trees.root_person_id\"}}}','Returns before/after ancestor path and person coverage counts, stale-row counts, and rebuild timings.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','coverage_rebuild',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for safe Genea coverage maintenance; service enforces dry-run and confirm flags.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (13,'research_task_create','App\\Engine\\MCPRouter','callTool','Dry-run-first creation of guarded genealogy research tasks.','{\"type\": \"object\", \"required\": [\"tree_id\", \"task_type\", \"priority\", \"research_question\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID for the task\"}, \"priority\": {\"type\": \"string\", \"description\": \"urgent, high, medium, or low\"}, \"person_id\": {\"type\": \"integer\", \"description\": \"Optional person ID the task is about\"}, \"task_type\": {\"type\": \"string\", \"description\": \"find_records, verify_facts, find_relatives, analyze_dna, suggest_sources, or transcribe_document\"}, \"parameters\": {\"type\": \"object\"}, \"scope_reason\": {\"type\": \"string\", \"description\": \"Scope boundaries and evidence standard\"}, \"outcome_state\": {\"type\": \"string\", \"default\": \"needs_research\"}, \"outcome_reason\": {\"type\": \"string\"}, \"conflicts_found\": {\"type\": \"string\"}, \"sources_checked\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"evidence_summary\": {\"type\": \"string\"}, \"selection_reason\": {\"type\": \"string\", \"description\": \"Why this task should be queued\"}, \"research_question\": {\"type\": \"string\", \"description\": \"Research question to queue\"}, \"related_people_used\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}}}','Returns the task creation dry-run plan or created genealogy_research_tasks id.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','research_task_create',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for safe Genea research-task queueing; service enforces dry-run and confirm flags.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (14,'lesson_memory_lookup','App\\Engine\\MCPRouter','callTool','Read compact reusable Genea research, OCR/document, source-capture, identity, and offline workflow lessons for a tree.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 20, \"description\": \"Maximum lessons to return\"}, \"query\": {\"type\": \"string\", \"description\": \"Optional text search over lesson title/value\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to search\"}, \"lesson_type\": {\"type\": \"string\", \"default\": \"all\", \"description\": \"all or one lesson type\"}}}','Returns the Genea lesson-memory MCP payload with compact lookup rows or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,50,'genealogy','lesson_memory_lookup',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for reusable local Genea lessons so agents can reuse research, OCR/document, source-capture, identity, and offline workflow wisdom.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (15,'lesson_memory_save','App\\Engine\\MCPRouter','callTool','Dry-run-first Genea MCP tool to store reusable research, document/OCR, source-capture, identity, and offline workflow lessons in tree-scoped semantic memory.','{\"type\": \"object\", \"required\": [\"tree_id\", \"lesson_type\", \"title\", \"lesson\"], \"properties\": {\"tags\": {\"type\": \"array\", \"items\": {\"type\": \"string\"}}, \"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"title\": {\"type\": \"string\", \"description\": \"Short reusable lesson title\"}, \"lesson\": {\"type\": \"string\", \"description\": \"Reviewed lesson text with enough context to reuse safely\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID the lesson applies to\"}, \"task_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"media_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"confidence\": {\"type\": \"number\", \"default\": 0.8}, \"person_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"source_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"lesson_type\": {\"type\": \"string\", \"description\": \"research_process_lesson, document_interpretation_lesson, source_capture_lesson, identity_decision_lesson, or offline_workflow_lesson\"}}}','Returns the Genea lesson-memory MCP payload with compact lookup rows or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','lesson_memory_save',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for reusable local Genea lessons so agents can reuse research, OCR/document, source-capture, identity, and offline workflow wisdom.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (16,'lesson_memory_context','App\\Engine\\MCPRouter','callTool','Read compact reusable Genea lessons for a tree/person/media/source/task context without exposing raw memory tables.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 8, \"description\": \"Maximum lessons to return\"}, \"query\": {\"type\": \"string\", \"description\": \"Optional extra text query\"}, \"task_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree research task ID\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to search\"}, \"media_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree media ID\"}, \"person_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree person ID\"}, \"source_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree source ID\"}, \"lesson_type\": {\"type\": \"string\", \"default\": \"all\", \"description\": \"all or one lesson type\"}}}','Returns compact lesson rows and a prompt-ready guardrail context_text for the requested tree/entity context.','[\"genealogy:read\"]','read','genealogy',0,50,'genealogy','lesson_memory_context',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for context-aware Genea lesson retrieval so agents can inject relevant local lessons without raw memory SQL.','2026-05-25 14:11:41','2026-05-25 14:11:41');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (17,'memory_backfill_batch','App\\Engine\\MCPRouter','callTool','Run compact bounded Genea learning-memory backfills across canonical lessons, health-audit findings, media-intake outcomes, source-media capture outcomes, and review decisions.','{\"type\": \"object\", \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"lanes\": {\"type\": \"string\", \"default\": \"all\", \"description\": \"all or comma-separated lanes\"}, \"limit\": {\"type\": \"integer\", \"default\": 25, \"description\": \"Maximum candidates per lane\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Optional tree ID. Omit only in trusted scheduled contexts.\"}}}','Returns per-tree lane summaries, candidate counts, recorded memory IDs, and errors without exposing raw memory tables.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','memory_backfill_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration for scheduled/local Genea learning backfills so agents can grow memory without raw SQL or multi-tool orchestration.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (18,'family_profile','App\\Engine\\MCPRouter','callTool','Read a complete tree-scoped family profile with spouses, children, family/member media, sources, and citations.','{\"type\": \"object\", \"required\": [\"tree_id\", \"family_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 25, \"description\": \"Maximum rows per related section\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns the family\"}, \"family_id\": {\"type\": \"integer\", \"description\": \"Family ID to inspect\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,30,'genealogy','family_profile',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (19,'rag_status','App\\Engine\\MCPRouter','callTool','Read genealogy person/source/media RAG and person embedding coverage for one tree or all trees.','{\"type\": \"object\", \"properties\": {\"tree_id\": {\"type\": \"integer\", \"description\": \"Optional tree ID to inspect\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,20,'genealogy','rag_status',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (20,'media_profile','App\\Engine\\MCPRouter','callTool','Read one genealogy media row with paths, text excerpts, links, citations, and face-match hints.','{\"type\": \"object\", \"required\": [\"tree_id\", \"media_id\"], \"properties\": {\"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns the media row\"}, \"media_id\": {\"type\": \"integer\", \"description\": \"Genealogy media ID to inspect\"}, \"face_limit\": {\"type\": \"integer\", \"default\": 25, \"description\": \"Maximum face-match rows\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,40,'genealogy','media_profile',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (21,'media_review_packet','App\\Engine\\MCPRouter','callTool','Read a compact document/OCR/media evidence packet with text quality, links, citations, hints, and next actions.','{\"type\": \"object\", \"required\": [\"tree_id\", \"media_id\"], \"properties\": {\"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns the media row\"}, \"media_id\": {\"type\": \"integer\", \"description\": \"Genealogy media ID to inspect\"}, \"text_limit\": {\"type\": \"integer\", \"default\": 1600, \"description\": \"Maximum characters per text excerpt\"}, \"summary_only\": {\"type\": \"boolean\", \"default\": false, \"description\": \"Suppress long text excerpts while preserving counts and hints\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,40,'genealogy','media_review_packet',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (22,'media_ocr_escalation_batch','App\\Engine\\MCPRouter','callTool','Read compact OCR/HTR/vision escalation candidates for weak or missing media text.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 50, \"description\": \"Maximum candidates\"}, \"bucket\": {\"type\": \"string\", \"default\": \"all\", \"description\": \"all, weak_text, html_text_extraction, document_text_extraction, image_ocr_or_vision, or processing_failed\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to inspect\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,20,'genealogy','media_ocr_escalation_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (23,'person_fact_extract','App\\Engine\\MCPRouter','callTool','Read candidate DOB/DOD/place/name/family facts from one media, source, or reviewed text packet with conflict flags.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns the evidence and target person\"}, \"media_id\": {\"type\": \"integer\", \"description\": \"Optional media packet to inspect\"}, \"person_id\": {\"type\": \"integer\", \"description\": \"Optional target person\"}, \"source_id\": {\"type\": \"integer\", \"description\": \"Optional source packet to inspect\"}, \"text_limit\": {\"type\": \"integer\", \"default\": 5000, \"description\": \"Maximum evidence text characters\"}, \"document_text\": {\"type\": \"string\", \"description\": \"Optional reviewed text packet\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,30,'genealogy','person_fact_extract',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (24,'proposal_queue','App\\Engine\\MCPRouter','callTool','Read tree-scoped genealogy proposal queue rows for pending, approved, rejected, or applied proposals.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 50, \"description\": \"Maximum rows per proposal type\"}, \"status\": {\"type\": \"string\", \"default\": \"pending\", \"description\": \"pending, approved, rejected, or applied\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to inspect\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,30,'genealogy','proposal_queue',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (25,'memory_report','App\\Engine\\MCPRouter','callTool','Read Genea memory signals: semantic facts, non-FT name guardrails, review signals, learned procedures, and episodes.','{\"type\": \"object\", \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 20, \"description\": \"Maximum recent semantic memories\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Optional tree ID for tree-scoped memory\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\"]','read','genealogy',0,20,'genealogy','memory_report',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (26,'name_variant_add','App\\Engine\\MCPRouter','callTool','Dry-run-first addition of a vetted maiden, married, alias, nickname, religious, or phonetic name variant for one tree-scoped person.','{\"type\": \"object\", \"required\": [\"tree_id\", \"person_id\", \"name_type\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"notes\": {\"type\": \"string\", \"description\": \"Evidence note explaining why this variant is valid\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"surname\": {\"type\": \"string\", \"description\": \"Variant surname\"}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns the person\"}, \"name_type\": {\"type\": \"string\", \"description\": \"birth, married, maiden, alias, nickname, religious, or phonetic\"}, \"person_id\": {\"type\": \"integer\", \"description\": \"Person ID to receive the variant\"}, \"source_id\": {\"type\": \"integer\", \"description\": \"Optional same-tree source ID\"}, \"given_names\": {\"type\": \"string\", \"description\": \"Variant given names\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','name_variant_add',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (27,'nara_placeholder_capture_batch','App\\Engine\\MCPRouter','callTool','Dry-run-first bounded capture of NARA Catalog URL-only genealogy_media placeholders into tree-scoped FT storage.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 25, \"description\": \"Maximum placeholder media rows\"}, \"compact\": {\"type\": \"boolean\", \"default\": true}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID whose NARA placeholder media rows should be captured\"}, \"max_bytes\": {\"type\": \"integer\"}, \"media_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"confirm_download\": {\"type\": \"boolean\", \"default\": false}, \"metadata_snapshot\": {\"type\": \"boolean\", \"default\": true}, \"confirm_storage_write\": {\"type\": \"boolean\", \"default\": false}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','nara_placeholder_capture_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (28,'person_media_link_retire','App\\Engine\\MCPRouter','callTool','Dry-run-first retirement of bad person-media link rows with optional matching imported-media citation cleanup.','{\"type\": \"object\", \"required\": [\"tree_id\", \"person_media_ids\", \"reason\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"reason\": {\"type\": \"string\", \"description\": \"Evidence/review reason\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID that owns the person-media links\"}, \"person_media_ids\": {\"type\": \"array\", \"items\": {\"type\": \"integer\"}}, \"retire_imported_citations\": {\"type\": \"boolean\", \"default\": true}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','person_media_link_retire',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (29,'media_link_integrity','App\\Engine\\MCPRouter','callTool','Dry-run-first audit and deterministic repair of missing person-media links from primary photos, citations, and approved face matches.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 25}, \"repair\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to inspect or repair\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','media_link_integrity',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (30,'person_source_link_integrity','App\\Engine\\MCPRouter','callTool','Dry-run-first audit and deterministic repair of missing person-source links from existing citation rows.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"limit\": {\"type\": \"integer\", \"default\": 25}, \"repair\": {\"type\": \"boolean\", \"default\": false}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to inspect or repair\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','person_source_link_integrity',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (31,'media_rag_batch','App\\Engine\\MCPRouter','callTool','Run bounded genealogy media RAG indexing stats, dry-run, or confirmed batch without shell access.','{\"type\": \"object\", \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 20}, \"stats\": {\"type\": \"boolean\", \"default\": false}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Optional tree ID to scope indexing\"}, \"max_seconds\": {\"type\": \"integer\", \"default\": 45}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','media_rag_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (32,'rag_index_batch','App\\Engine\\MCPRouter','callTool','Run bounded genealogy person/place/source RAG indexing stats, dry-run, or confirmed batch without shell access.','{\"type\": \"object\", \"properties\": {\"type\": {\"type\": \"string\", \"default\": \"persons\"}, \"limit\": {\"type\": \"integer\", \"default\": 20}, \"stats\": {\"type\": \"boolean\", \"default\": false}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"reindex\": {\"type\": \"boolean\", \"default\": false}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Optional tree ID to scope indexing\"}, \"exclude_living\": {\"type\": \"boolean\", \"default\": false}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','rag_index_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (33,'person_embedding_batch','App\\Engine\\MCPRouter','callTool','Run bounded genealogy person embedding stats, dry-run preview, or confirmed batch without shell access.','{\"type\": \"object\", \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 50}, \"stats\": {\"type\": \"boolean\", \"default\": false}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"reindex\": {\"type\": \"boolean\", \"default\": false}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Optional tree ID to scope embedding\"}, \"exclude_living\": {\"type\": \"boolean\", \"default\": false}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','person_embedding_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (34,'media_htr_batch','App\\Engine\\MCPRouter','callTool','Run bounded genealogy HTR transcription status, dry-run, or confirmed batch without shell access.','{\"type\": \"object\", \"properties\": {\"limit\": {\"type\": \"integer\", \"default\": 10}, \"status\": {\"type\": \"boolean\", \"default\": false}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Optional tree ID to scope transcription candidates\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,10,'genealogy','media_htr_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (35,'media_intake_memory_batch','App\\Engine\\MCPRouter','callTool','Backfill saved genealogy media-intake run outcomes into tree-scoped Genea semantic memory.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"limit\": {\"type\": \"integer\", \"default\": 50}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID whose saved intake runs should be memorized\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','media_intake_memory_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (36,'review_decision_memory_batch','App\\Engine\\MCPRouter','callTool','Backfill accepted/rejected genealogy proposal decisions into tree-scoped Genea semantic memory.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"limit\": {\"type\": \"integer\", \"default\": 50}, \"status\": {\"type\": \"string\", \"default\": \"applied,rejected\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to process\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','review_decision_memory_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `agent_tool_registry` (`id`, `name`, `service_class`, `method`, `description`, `parameters`, `returns_description`, `permissions`, `risk_level`, `category`, `requires_confirmation`, `max_calls_per_run`, `mcp_server`, `mcp_tool`, `max_tokens_per_call`, `enabled`, `source`, `proposed_by`, `approved_by`, `approved_at`, `notes`, `created_at`, `updated_at`) VALUES (37,'review_packet_memory_batch','App\\Engine\\MCPRouter','callTool','Backfill accepted/rejected genealogy review-packet outcomes into tree-scoped Genea semantic memory.','{\"type\": \"object\", \"required\": [\"tree_id\"], \"properties\": {\"actor\": {\"type\": \"string\", \"default\": \"genea-mcp\"}, \"limit\": {\"type\": \"integer\", \"default\": 50}, \"status\": {\"type\": \"string\", \"default\": \"reviewed,rejected\"}, \"confirm\": {\"type\": \"boolean\", \"default\": false}, \"dry_run\": {\"type\": \"boolean\", \"default\": true}, \"tree_id\": {\"type\": \"integer\", \"description\": \"Tree ID to process\"}}}','Returns the Genea MCP payload with compact read data or dry-run/write-audit status.','[\"genealogy:read\", \"genealogy:write\"]','write','genealogy',0,20,'genealogy','review_packet_memory_batch',NULL,1,'config',NULL,NULL,NULL,'MCP bridge registration so Genea agents can use current tree-scoped Genea review, media, memory, RAG, and ingestion tools without raw SQL or shell access.','2026-05-25 14:11:42','2026-05-25 14:11:42');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (1,'bbci.co.uk','BBC News',1,'BBC RSS feed host emitted by feeds.bbci.co.uk after source-host normalization.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (2,'abcnews.go.com','ABC News',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (3,'ap.org','Associated Press',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (4,'apnews.com','Associated Press',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (5,'bbc news','BBC',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (6,'bbc.co.uk','BBC',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (7,'bbc.com','BBC',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (8,'bloomberg.com','Bloomberg',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (9,'breitbart.com','Breitbart News',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (10,'businessinsider.com','Business Insider',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (11,'cbsnews.com','CBS News',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (12,'cnn','CNN',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (13,'cnn.com','CNN',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (14,'economist.com','The Economist',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (15,'forbes.com','Forbes',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (16,'fox news','Fox News',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (17,'foxnews.com','Fox Online News',1,'Fox RSS feed host emitted by feeds.foxnews.com after source-host normalization.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (18,'huffingtonpost.com','HuffPost',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (19,'huffpost.com','HuffPost',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (20,'latimes.com','Los Angeles Times',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (21,'msnbc.com','MSNBC',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (22,'nbcnews.com','NBC News',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (23,'newsweek.com','Newsweek',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (24,'npr.org','NPR',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (25,'nypost.com','New York Post',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (26,'nyt','The New York Times',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (27,'nytimes.com','The New York Times',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (28,'pbs.org','PBS',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (29,'politico.com','Politico',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (30,'reuters.com','Reuters',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (31,'slate.com','Slate',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (32,'theatlantic.com','The Atlantic',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (33,'theguardian.com','The Guardian',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (34,'thehill.com','The Hill',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (35,'time.com','Time',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (36,'usatoday.com','USA Today',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (37,'vox.com','Vox',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (38,'washingtonpost.com','Washington Post',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (39,'wsj.com','Wall Street Journal',1,'Seeded from legacy BiasRatingService source normalization; operator-editable alias.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (40,'moxie.foxnews.com','Fox Online News',1,'Fox News Google Publisher RSS host used by U.S. and world feed sections.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (41,'fox news - latest headlines','Fox Online News',1,'news_brief feed label for the general Fox News RSS feed.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (42,'fox news - politics','Fox Online News',1,'news_brief feed label for the Fox News politics RSS feed.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (43,'fox news - u.s. news','Fox Online News',1,'news_brief feed label for the Fox News U.S. RSS feed.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (44,'fox news - world','Fox Online News',1,'news_brief feed label for the Fox News world RSS feed.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (45,'realnewsnotbs.com','Real News No Bullshit',1,'Canonical feed/site host. Operator manual center rating for local news_brief feed coverage. No MBFC/AllSides-derived rating was matched as of 2026-04-30; revisit if a third-party dataset adds this source.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (46,'www.realnewsnotbs.com','Real News No Bullshit',1,'Canonical feed/site host with www prefix. Operator manual center rating for local news_brief feed coverage. No MBFC/AllSides-derived rating was matched as of 2026-04-30; revisit if a third-party dataset adds this source.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (47,'real news no bullshit','Real News No Bullshit',1,'news_brief feed label without subtitle. Operator manual center rating for local news_brief feed coverage. No MBFC/AllSides-derived rating was matched as of 2026-04-30; revisit if a third-party dataset adds this source.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_rating_aliases` (`id`, `alias`, `canonical_source`, `active`, `notes`, `created_at`, `updated_at`) VALUES (48,'real news no bullshit - unbiased news without agenda','Real News No Bullshit',1,'Full news_brief feed label. Operator manual center rating for local news_brief feed coverage. No MBFC/AllSides-derived rating was matched as of 2026-04-30; revisit if a third-party dataset adds this source.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `bias_ratings` (`id`, `news_source`, `rating`, `rating_num`, `data_source`, `mbfc_factual_rating`, `mbfc_credibility_score`, `is_polarizing_source`, `type`, `agree`, `disagree`, `perc_agree`, `url`, `editorial_review`, `blind_survey`, `third_party_analysis`, `independent_research`, `confidence_level`, `twitter`, `wiki`, `facebook`, `screen_name`, `notes`, `created_at`, `updated_at`) VALUES (1,'Real News No Bullshit','center',0,'manual',NULL,NULL,0,'News Media',NULL,NULL,NULL,'https://www.realnewsnotbs.com',0,0,0,0,'operator-manual',NULL,NULL,NULL,'realnewsnotbs.com','Operator manual center rating for local news_brief feed coverage. No MBFC/AllSides-derived rating was matched as of 2026-04-30; revisit if a third-party dataset adds this source.','2026-05-25 14:11:35','2026-05-25 14:11:35');
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (1,'wikitree','wikitree_id','%wikitree.com%','WikiTree',1);
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (2,'findagrave','findagrave_id','%findagrave.com%','Find A Grave',1);
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (3,'ancestry','ancestry_id','%ancestry.com%','Ancestry',1);
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (4,'familysearch','familysearch_id','%familysearch.org%','FamilySearch',1);
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (5,'geni','geni_id','%geni.com%','Geni',1);
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (6,'myheritage','myheritage_id','%myheritage.com%','MyHeritage',1);
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (7,'geneanet','geneanet_id','%geneanet.org%','Geneanet',1);
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (8,'findmypast','findmypast_id','%findmypast.com%','FindMyPast',1);
INSERT INTO `genealogy_external_service_registry` (`id`, `service_type`, `field_alias`, `url_pattern`, `display_name`, `is_active`) VALUES (9,'nara','nara_id','%catalog.archives.gov%','National Archives (NARA)',1);
INSERT INTO `llm_instances` (`id`, `instance_id`, `instance_name`, `instance_type`, `base_url`, `port`, `api_key_env`, `api_key`, `priority`, `is_active`, `routability`, `gpu_target`, `host_affinity`, `compat_runtime_family`, `compat_backend`, `compat_status`, `is_healthy`, `health_score`, `capabilities`, `is_censored`, `allows_private_data`, `data_privacy_scope`, `privacy_reviewed_at`, `privacy_notes`, `supported_models`, `context_length`, `embedding_context_length`, `avg_response_ms`, `p95_response_ms`, `total_requests`, `total_failures`, `consecutive_failures`, `success_rate`, `circuit_state`, `circuit_opened_at`, `circuit_retry_at`, `max_concurrent`, `rate_limit_rpm`, `rate_limit_tpm`, `cost_per_1k_input`, `cost_per_1k_output`, `cost_tier`, `config`, `notes`, `last_health_check`, `last_success_at`, `last_failure_at`, `created_at`, `updated_at`) VALUES (1,'codex_exec','OpenAI Codex Exec','codex_cli',NULL,NULL,'OPENAI_API_KEY',NULL,18,0,'allowed','none','prod','codex-cli','openai','authoritative',1,100,'[\"text\", \"code\", \"tools\", \"repository\", \"jsonl\", \"structured_output\"]',1,1,'private_allowed','2026-05-25 14:11:42','OpenAI Codex Exec is the approved external LLM partner for private PLOS/Genea/dev pipeline work.','[\"gpt-5.5\", \"gpt-5.4\", \"gpt-5.4-mini\", \"gpt-5.3-codex\", \"gpt-5.2\"]',128000,NULL,30000.00,90000.00,0,0,0,100.00,'closed',NULL,NULL,1,6,NULL,NULL,NULL,'premium','{\"models\": {\"fast\": \"gpt-5.4-mini\", \"coding\": \"gpt-5.5\", \"quality\": \"gpt-5.5\", \"standard\": \"gpt-5.5\"}, \"adapter\": \"codex_exec\", \"cwd_roots\": [\"/opt/plos/app\"], \"ephemeral\": true, \"executable\": \"codex\", \"default_cwd\": \"/opt/plos/app\", \"json_events\": true, \"default_profile\": null, \"default_sandbox\": \"read-only\", \"sandbox_by_role\": {\"fast\": \"read-only\", \"coding\": \"workspace-write\", \"quality\": \"read-only\", \"standard\": \"read-only\"}, \"max_prompt_bytes\": 200000, \"reasoning_effort\": {\"fast\": \"low\", \"coding\": \"high\", \"quality\": \"high\", \"standard\": \"medium\"}, \"structured_output\": {\"enabled\": true, \"schema_file_roots\": [\"/opt/plos/app/storage/app/codex-schemas\"]}, \"output_last_message\": true, \"skip_git_repo_check\": true, \"allow_live_extra_models\": true, \"default_approval_policy\": \"never\", \"default_timeout_seconds\": 900, \"supported_reasoning_efforts\": [\"low\", \"medium\", \"high\", \"xhigh\"]}','Codex Exec is a bounded online external LLM partner for PLOS/Genea/dev pipeline tasks. Model and reasoning effort are resolved from llm_instances.config; approval_policy must remain never for pipeline execution.','2026-05-25 14:11:42',NULL,NULL,'2026-05-25 14:11:42','2026-05-25 14:12:11');
INSERT INTO `review_type_registry` (`id`, `name`, `label`, `icon`, `category`, `source_table`, `source_connection`, `count_sql`, `fetch_sql`, `approve_sql`, `reject_sql`, `ignore_sql`, `field_mapping`, `ui_schema`, `vue_renderer`, `vue_detail_component`, `actions`, `requires_image`, `image_field`, `batch_enabled`, `service_class`, `approve_method`, `reject_method`, `display_order`, `color`, `enabled`, `created_at`, `updated_at`) VALUES (1,'genealogy_merge','Genealogy Merges','mdi-call-merge','genealogy','agent_review_queue','mysql','SELECT COUNT(*) as total FROM agent_review_queue WHERE status = \'pending\' AND review_type = \'genealogy_merge\' AND (expires_at IS NULL OR expires_at > NOW())','SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = \'pending\' AND review_type = \'genealogy_merge\' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100','UPDATE agent_review_queue SET status = \'approved\', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?','UPDATE agent_review_queue SET status = \'rejected\', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?',NULL,'{\"id\": \"id\", \"title\": \"title\", \"token\": \"token\", \"summary\": \"summary\", \"agent_id\": \"agent_id\", \"priority\": \"priority\", \"confidence\": \"confidence\", \"created_at\": \"created_at\", \"expires_at\": \"expires_at\", \"review_type\": \"review_type\", \"details_json\": \"details\", \"unified_id_template\": \"genealogy_merge:{{token}}\"}','{\"card\": {\"body\": [{\"type\": \"text\", \"class\": \"text-sm text-ops-text-muted\", \"source\": \"summary\"}], \"footer\": [{\"type\": \"timestamp\", \"label\": \"Created\", \"source\": \"created_at\"}, {\"type\": \"timestamp\", \"label\": \"Expires\", \"source\": \"expires_at\", \"warn_if_soon\": true}], \"header\": [{\"type\": \"badge\", \"class\": \"bg-ops-sky\", \"source\": \"agent_id\"}, {\"type\": \"badge\", \"class\": \"bg-ops-blue\", \"source\": \"review_type\"}, {\"type\": \"text\", \"class\": \"font-semibold text-ops-peach flex-1\", \"source\": \"title\"}, {\"type\": \"confidence\", \"source\": \"confidence\"}]}, \"detail\": [{\"type\": \"text\", \"label\": \"Agent\", \"source\": \"agent_id\"}, {\"type\": \"text\", \"label\": \"Review Type\", \"source\": \"review_type\"}, {\"type\": \"json\", \"label\": \"Details\", \"source\": \"details\", \"collapsible\": true}]}',NULL,NULL,'[\"approve\", \"reject\"]',0,NULL,1,NULL,NULL,NULL,36,'ops-blue',1,'2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `review_type_registry` (`id`, `name`, `label`, `icon`, `category`, `source_table`, `source_connection`, `count_sql`, `fetch_sql`, `approve_sql`, `reject_sql`, `ignore_sql`, `field_mapping`, `ui_schema`, `vue_renderer`, `vue_detail_component`, `actions`, `requires_image`, `image_field`, `batch_enabled`, `service_class`, `approve_method`, `reject_method`, `display_order`, `color`, `enabled`, `created_at`, `updated_at`) VALUES (2,'genealogy_review_packet','Genealogy Review Packets','mdi-file-document-check','genealogy','agent_review_queue','mysql','SELECT COUNT(*) as total FROM agent_review_queue WHERE status = \'pending\' AND review_type = \'genealogy_review_packet\' AND (expires_at IS NULL OR expires_at > NOW())','SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = \'pending\' AND review_type = \'genealogy_review_packet\' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100',NULL,NULL,'UPDATE agent_review_queue SET status = \'ignored\', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?','{\"id\": \"id\", \"title\": \"title\", \"token\": \"token\", \"summary\": \"summary\", \"agent_id\": \"agent_id\", \"priority\": \"priority\", \"confidence\": \"confidence\", \"created_at\": \"created_at\", \"expires_at\": \"expires_at\", \"review_type\": \"review_type\", \"details_json\": \"details\", \"unified_id_template\": \"genealogy_review_packet:{{token}}\"}','{\"card\": {\"body\": [{\"type\": \"text\", \"class\": \"text-sm text-ops-text-muted\", \"source\": \"summary\"}], \"footer\": [{\"type\": \"timestamp\", \"label\": \"Created\", \"source\": \"created_at\"}, {\"type\": \"timestamp\", \"label\": \"Expires\", \"source\": \"expires_at\", \"warn_if_soon\": true}], \"header\": [{\"type\": \"badge\", \"class\": \"bg-ops-green\", \"source\": \"review_type\"}, {\"type\": \"text\", \"class\": \"font-semibold text-ops-peach flex-1\", \"source\": \"title\"}, {\"type\": \"confidence\", \"source\": \"confidence\"}]}, \"detail\": [{\"type\": \"text\", \"label\": \"Agent\", \"source\": \"agent_id\"}, {\"type\": \"json\", \"label\": \"Packet Details\", \"source\": \"details\", \"expanded\": true}]}',NULL,NULL,'[\"approve\", \"reject\", \"clarify\", \"defer\", \"ignore\"]',0,NULL,0,'App\\Services\\Genealogy\\GenealogyReviewPacketDecisionService','approve','reject',37,'ops-green',1,'2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `review_type_registry` (`id`, `name`, `label`, `icon`, `category`, `source_table`, `source_connection`, `count_sql`, `fetch_sql`, `approve_sql`, `reject_sql`, `ignore_sql`, `field_mapping`, `ui_schema`, `vue_renderer`, `vue_detail_component`, `actions`, `requires_image`, `image_field`, `batch_enabled`, `service_class`, `approve_method`, `reject_method`, `display_order`, `color`, `enabled`, `created_at`, `updated_at`) VALUES (3,'genealogy_evidence_asset_capture','Genealogy Evidence Media Capture','mdi-file-image-plus','genealogy','agent_review_queue','mysql','SELECT COUNT(*) as total FROM agent_review_queue WHERE status = \'pending\' AND review_type = \'genealogy_evidence_asset_capture\' AND (expires_at IS NULL OR expires_at > NOW())','SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = \'pending\' AND review_type = \'genealogy_evidence_asset_capture\' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100',NULL,NULL,'UPDATE agent_review_queue SET status = \'ignored\', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?','{\"id\": \"id\", \"title\": \"title\", \"token\": \"token\", \"summary\": \"summary\", \"agent_id\": \"agent_id\", \"priority\": \"priority\", \"confidence\": \"confidence\", \"created_at\": \"created_at\", \"expires_at\": \"expires_at\", \"review_type\": \"review_type\", \"details_json\": \"details\", \"unified_id_template\": \"genealogy_evidence_asset_capture:{{token}}\"}','{\"card\": {\"body\": [{\"type\": \"text\", \"class\": \"text-sm text-ops-text-muted\", \"source\": \"summary\"}], \"footer\": [{\"type\": \"timestamp\", \"label\": \"Created\", \"source\": \"created_at\"}, {\"type\": \"timestamp\", \"label\": \"Expires\", \"source\": \"expires_at\", \"warn_if_soon\": true}], \"header\": [{\"type\": \"badge\", \"class\": \"bg-ops-amber\", \"source\": \"review_type\"}, {\"type\": \"text\", \"class\": \"font-semibold text-ops-peach flex-1\", \"source\": \"title\"}, {\"type\": \"confidence\", \"source\": \"confidence\"}]}, \"detail\": [{\"type\": \"text\", \"label\": \"Agent\", \"source\": \"agent_id\"}, {\"type\": \"json\", \"label\": \"Capture approval details\", \"source\": \"details\", \"expanded\": true}]}',NULL,NULL,'[\"approve\", \"reject\", \"ignore\"]',0,NULL,0,'App\\Services\\Genealogy\\GenealogyEvidenceAssetCaptureDecisionService','approve','reject',38,'ops-amber',1,'2026-05-25 14:11:40','2026-05-25 14:11:40');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (1,'scheduler_synthetic_probe','Lightweight synthetic scheduler proof job. Updates a heartbeat-style marker via scheduled execution.','command','ops:scheduler-synthetic-probe','*/15 * * * *',0,1,1,0,5,1,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,NULL,'Ops','Ops',NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-25 14:11:33','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (2,'knowledge_graph_catchup','Offset stale-first knowledge graph catch-up pass for APL #1A backlog burn-down','command','rag:build-knowledge-graph --limit=100 --sleep=750 --min-chars=50 --max-chars=8000 --backlog=all --order=stale-first --budget-minutes=95','15 2-22/4 * * *',0,1,1,1,110,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'APL #1A catch-up lane: bounded stale-first KG pass between primary 4-hour runs. Target about +600 docs/day while leaving headroom before the next main KG slot.','RAG','KnowledgeGraph','cron','rag','rag','stall_exempt','none','digest','2026-05-25 14:11:34','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (3,'ops_host_baseline_jobs_heavy_window','Capture three host baseline samples during the 4 AM heavy scheduled-job window for TODO-011 capacity evidence','command','ops:host-baseline jobs --repeat=3 --interval=900','5 4 * * *',0,1,1,1,45,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'TODO-011 evidence collector: captures jobs baselines at about 04:05, 04:20, and 04:35 America/New_York. Non-mutating telemetry only; ops:capacity-report remains observe-only until enough heavy-window and deploy samples exist.','Maintenance','OpsCapacity','observe','ops','default','stall_exempt','none','digest','2026-05-25 14:11:34','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (4,'bias_data_refresh','Refresh free news-bias ratings and supporting bias data for news workflows','command','bias:maintenance --all --source=free','20 3 1 * *',0,1,1,1,60,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'NewsBias monthly free/default refresh uses the Idiap MBFC-derived GitHub dataset. AllSides is excluded from scheduled refresh and remains an explicit operator-selected enrichment source via --source=allsides or --source=both.','Maintenance','Maintenance','maintenance','news','default','stall_exempt','bias_ratings','digest','2026-05-25 14:11:35','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (5,'news_source_inventory','Read-only inventory of table-backed news RSS feeds, health, recent article counts, and bias-rating coverage','command','news:source-inventory --workflow=news_brief --days=7 --strict --json','40 5 * * 0',0,1,1,0,10,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Weekly observe-only check after RSS self-heal and before daily report. Verifies configured news_brief RSS feeds resolve through table-backed bias_ratings/bias_rating_aliases and have recent health/article telemetry.','Maintenance','News','maintenance','news','default','strict','rss_feeds','digest','2026-05-25 14:11:36','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (6,'face_link_weekly_report','Weekly observe-only face/genealogy bridge telemetry report captured in scheduled job output','command','ops:face-telemetry-report --markdown --hours=168','20 5 * * 1',0,1,1,0,5,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'TODO face/genealogy loop: weekly read-only 168-hour report after Sunday full recluster and Monday schema sync. Output is retained in scheduled_job_runs; no notification or remediation write is performed.','Maintenance','Genealogy','maintenance','genealogy','default','strict','face_links','digest','2026-05-25 14:11:36','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (7,'awo_replay_weekly_report','Weekly observe-only approval-worthy-output replay report captured in scheduled job output','command','awo:replay --window=7d --limit=500 --markdown','30 5 * * 1',0,1,1,0,5,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Agent output quality: weekly read-only AWO replay report retained in scheduled_job_runs. It does not enable awo.recording_enabled, promote agents, or mutate review state.','Maintenance','Agents','maintenance','agents','default','strict','agent_review_queue','digest','2026-05-25 14:11:36','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (8,'kg_provenance_snapshot','Capture daily knowledge-graph provenance audit counts into pipeline metrics snapshots','command','graph:snapshot-provenance --json','25 5 * * *',0,1,1,0,10,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Daily observe-only KG provenance evidence after the overnight heavy window. Writes one idempotent kg_provenance row per date to pipeline_metrics_snapshots.','Maintenance','RAG','maintenance','rag','default','strict','kg_provenance','digest','2026-05-25 14:11:38','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (9,'scheduler_optimize_weekly_report','Capture weekly observe-only scheduler optimization recommendations','command','scheduler:optimize-report --window=7d --json','45 5 * * 2',0,1,1,0,10,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Weekly observe-only TODO-012 evidence. Stores scheduler optimization recommendations in scheduled job history without changing cron expressions, timeouts, queues, or job limits.','Maintenance','Ops','maintenance','ops','default','strict','scheduled_jobs','digest','2026-05-25 14:11:38','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (10,'genealogy_media_enrichment_status','Observe-only genealogy media enrichment status and quarantine report for captured/source media handoff','command','genealogy:enrich-media --status --quarantined','35 6 * * *',0,1,1,0,5,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'TODO-9M observe-only post-capture handoff report. No downloads, FT storage writes, genealogy links, review decisions, AI calls, or canonical genealogy writes are performed by this status command.','Maintenance','Genealogy','maintenance','genealogy','db','strict','genealogy_media_enrichment','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (11,'genealogy_media_enrichment_batch','Disabled genealogy media enrichment batch lane for operator activation after post-capture preflight is clean','command','genealogy:enrich-media --limit=5','50 6 * * *',0,1,1,0,60,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'TODO-9M disabled by default. Enabling this row can generate genealogy media enrichment proposals from eligible captured/source media; activate only after operator review of observe-only status and dry-run output.','Maintenance','Genealogy','batch','genealogy','ai','strict','genealogy_media_enrichment','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (12,'genealogy_rag_full_reindex','Monthly full rebuild of genealogy person, place, and source RAG documents across all family trees.','command','genealogy:rag-index --type=all --reindex --limit=0','10 1 1 * *',0,1,1,0,240,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Runs monthly on the first at 01:10. Uses --type=all --limit=0 so all family-tree person, place, and source RAG docs are rebuilt, including living and deceased persons on the private system. Do not schedule with --exclude-living.','Genealogy','Genealogy','maintenance','rag','heavy','strict','genealogy_rag','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (13,'genealogy_embed_persons_full_reindex','Monthly full rebuild of genealogy person semantic embeddings across all family trees.','command','genealogy:embed-persons --reindex --limit=0','40 3 1 * *',0,1,1,0,180,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Runs monthly on the first at 03:40 after the person profile full reindex. Uses --limit=0 so all family-tree person embeddings are rebuilt, including living and deceased persons on the private system. Do not schedule with --exclude-living.','Genealogy','Genealogy','maintenance','rag','heavy','strict','genealogy_person_embeddings','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (14,'genealogy_health_audit','Daily read-only genealogy health audit across all family trees.','command','genealogy:health-audit --all-trees --json --compact --limit=20','05 5 * * *',0,1,1,0,15,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Observe-only control-panel audit. Runs for every known family tree and performs no downloads, storage writes, genealogy links, review decisions, privacy/export release, or canonical record writes.','Genealogy','Genealogy','maintenance','genealogy','db','strict','genealogy_health_audit','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (15,'genealogy_media_rag_index','Incremental genealogy media metadata and transcription RAG indexing across all family trees.','command','genealogy:media-rag-index --limit=1500','27 */6 * * *',0,1,1,0,120,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Indexes readable genealogy_media metadata, OCR/transcription text, AI descriptions, filenames, and rejected/non-FT name context into local RAG across all trees. No --tree filter so new family trees are covered automatically.','Genealogy','Genealogy','batch','rag','ai','strict','genealogy_media_rag','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (16,'genealogy_duplicate_candidate_scan','Daily genealogy duplicate-person candidate scan across all family trees.','command','genealogy:duplicate-scan --all-trees --min-score=0.75 --limit=250 --json','35 5 * * *',0,1,1,0,30,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Review-first scan only. Creates or refreshes pending genealogy_duplicate_pairs rows with score/reasons, but does not merge people or mutate canonical person/family facts.','Genealogy','Genealogy','maintenance','genealogy','db','strict','genealogy_duplicate_candidates','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (17,'genealogy_export_readiness_check','Daily export-readiness check for self-contained genealogy trees.','command','genealogy:health-audit --all-trees --sections=export --json --compact --limit=25','55 5 * * *',0,1,1,0,15,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Observe-only export preflight. Checks every tree for non-self-contained media paths and missing export blockers without downloads, link changes, or person/family fact writes.','Genealogy','Genealogy','maintenance','genealogy','db','strict','genealogy_export_readiness','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (18,'genealogy_htr_status_check','Daily genealogy HTR/OCR eligibility and availability status check.','command','genealogy:transcribe-media --status','15 6 * * *',0,1,1,0,15,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Observe-only HTR/OCR readiness check. Reports pending transcription and eligibility reasons; does not transcribe or mutate media rows.','Genealogy','Genealogy','maintenance','genealogy','db','strict','genealogy_htr_eligibility','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (19,'genealogy_unlinked_media_review','Daily unlinked and missing genealogy media review audit across all trees.','command','genealogy:health-audit --all-trees --sections=media --json --compact --limit=50','25 6 * * *',0,1,1,0,15,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Observe-only media review. Surfaces unlinked media, missing local files, and external-only media without deleting files, linking records, or changing person/family facts.','Genealogy','Genealogy','maintenance','genealogy','db','strict','genealogy_unlinked_media','digest','2026-05-25 14:11:40','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (20,'genealogy_evidence_score_report','Daily observe-only genealogy evidence score report across all family trees.','command','genealogy:evidence-score --all-trees --json --limit=100','5 6 * * *',0,1,1,0,15,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Observe-only evidence scoring. Summarizes strong/medium/weak/conflict/missing evidence bands for genealogy proposals; does not approve, reject, apply, or mutate person/family/media facts.','Genealogy','Genealogy','maintenance','genealogy','db','strict','genealogy_evidence_scores','digest','2026-05-25 14:11:41','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (21,'genealogy_backfill_source_media','Frequent bounded genealogy source URL media backfill across all trees.','command','genealogy:backfill-source-media --mode=sources --tree=all --since=30d --limit=25 --order=oldest --confirm-download --confirm-storage-write --nara-metadata-snapshot --json','*/10 * * * *',0,1,1,0,25,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,'2026-05-25 14:11:41',0,0,'Captures URL-only genealogy_sources into tree-local FT storage in small frequent batches. Failed rows are marked source_media_backfill_blocked and skipped until retry_blocked is requested.','Genealogy','Genealogy','batch','genealogy','network','strict','genealogy_source_media_backfill','digest','2026-05-25 14:11:41','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (22,'genealogy_memory_backfill','Frequent bounded local Genea learning-memory backfill across all family trees.','command','genealogy:memory-backfill --tree=all --lanes=all --limit=25 --confirm --json','37 */6 * * *',0,1,1,0,20,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Runs dry-run-first Genea memory backfill in confirmed scheduled mode. Captures canonical lessons and memorizes accepted/rejected review, media-intake, source-media capture, and health-audit signals without raw table work.','Genealogy','Genealogy','batch','genealogy','db','strict','genealogy_learning_memory','digest','2026-05-25 14:11:42','2026-05-25 14:12:11');
INSERT INTO `scheduled_jobs` (`id`, `name`, `description`, `job_type`, `command`, `cron_expression`, `enabled`, `run_in_background`, `without_overlapping`, `stall_exempt`, `timeout_minutes`, `timeout_locked`, `last_run_at`, `last_completed_at`, `last_run_status`, `last_run_output`, `last_pid`, `max_parallel`, `running_pids`, `running_count`, `next_run_at`, `run_count`, `fail_count`, `notes`, `category`, `source_module`, `runtime_mode`, `workload_family`, `resource_profile`, `stall_policy`, `backlog_metric`, `notification_mode`, `created_at`, `updated_at`) VALUES (23,'agent_doctor_readiness_snapshot','Capture aggregate Agent Doctor readiness snapshots for trend history.','command','ops:agent-doctor-snapshot --json --since=24','17 */6 * * *',0,1,1,0,5,0,NULL,NULL,NULL,NULL,NULL,1,NULL,0,NULL,0,0,'Append-only observe snapshot for Agent Doctor history. Stores aggregate statuses, counts, check ids, and output-quality counts only; excludes per-agent detail, raw traces, prompts, completions, command output, and filesystem paths.','Maintenance','Ops','maintenance','ops','db','strict','agent_doctor_readiness','digest','2026-05-25 14:11:42','2026-05-25 14:12:11');
INSERT INTO `system_configs` (`id`, `section`, `config_key`, `config_value`, `data_type`, `description`, `created_at`, `updated_at`) VALUES (1,'routing','offline_mode','disabled','string','Fail-closed offline kill switch for INTERNET-level cloud LLMs only. Values: disabled (default, cloud fallback active) | enabled (block external cloud LLM providers and APIs). LAN services (Nextcloud, MySQL, PostgreSQL, Redis, local Ollama instances, local MCP, queue workers, scheduled jobs) stay fully online. See docs/OLLAMA-COMPATIBILITY.md and docs/AIService-LLM-Gateway.md.','2026-05-25 14:11:34','2026-05-25 14:11:34');
INSERT INTO `system_configs` (`id`, `section`, `config_key`, `config_value`, `data_type`, `description`, `created_at`, `updated_at`) VALUES (2,'awo','recording_enabled','false','bool','Default-off AWO operator-decision evidence recording. Stores compact awo_decision envelopes in agent_review_queue.details after final operator approve/reject only.','2026-05-25 14:11:34','2026-05-25 14:11:34');
