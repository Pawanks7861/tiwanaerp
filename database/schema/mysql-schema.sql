/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `ai_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `title` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_context` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_conversations_user_id_foreign` (`user_id`),
  KEY `ai_conversations_project_id_foreign` (`project_id`),
  KEY `ai_conversations_company_id_user_id_last_message_at_index` (`company_id`,`user_id`,`last_message_at`),
  CONSTRAINT `ai_conversations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_conversations_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_conversations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_conversation_id` bigint unsigned NOT NULL,
  `role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tool_calls` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `tool_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prompt_tokens` int unsigned NOT NULL DEFAULT '0',
  `completion_tokens` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_messages_ai_conversation_id_id_index` (`ai_conversation_id`,`id`),
  CONSTRAINT `ai_messages_ai_conversation_id_foreign` FOREIGN KEY (`ai_conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_usage_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_usage_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ai_conversation_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prompt_tokens` int unsigned NOT NULL DEFAULT '0',
  `completion_tokens` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `error_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_usage_logs_user_id_foreign` (`user_id`),
  KEY `ai_usage_logs_ai_conversation_id_foreign` (`ai_conversation_id`),
  KEY `ai_usage_logs_company_id_user_id_created_at_index` (`company_id`,`user_id`,`created_at`),
  CONSTRAINT `ai_usage_logs_ai_conversation_id_foreign` FOREIGN KEY (`ai_conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_usage_logs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_usage_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approval_request_id` bigint unsigned NOT NULL,
  `level` tinyint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comments` text COLLATE utf8mb4_unicode_ci,
  `acted_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_actions_user_id_foreign` (`user_id`),
  KEY `approval_actions_approval_request_id_level_index` (`approval_request_id`,`level`),
  CONSTRAINT `approval_actions_approval_request_id_foreign` FOREIGN KEY (`approval_request_id`) REFERENCES `approval_requests` (`id`),
  CONSTRAINT `approval_actions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_delegations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_delegations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `delegator_id` bigint unsigned NOT NULL,
  `delegate_id` bigint unsigned NOT NULL,
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NOT NULL,
  `document_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_delegations_delegator_id_foreign` (`delegator_id`),
  KEY `approval_delegations_delegate_id_foreign` (`delegate_id`),
  KEY `approval_delegations_created_by_foreign` (`created_by`),
  KEY `approval_delegations_company_id_delegate_id_is_active_index` (`company_id`,`delegate_id`,`is_active`),
  KEY `approval_delegations_company_id_delegator_id_is_active_index` (`company_id`,`delegator_id`,`is_active`),
  CONSTRAINT `approval_delegations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `approval_delegations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_delegations_delegate_id_foreign` FOREIGN KEY (`delegate_id`) REFERENCES `users` (`id`),
  CONSTRAINT `approval_delegations_delegator_id_foreign` FOREIGN KEY (`delegator_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `approvable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approvable_id` bigint unsigned NOT NULL,
  `approval_workflow_id` bigint unsigned NOT NULL,
  `steps` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `current_level` tinyint unsigned NOT NULL DEFAULT '1',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `submitted_by` bigint unsigned NOT NULL,
  `submitted_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_requests_approvable_type_approvable_id_index` (`approvable_type`,`approvable_id`),
  KEY `approval_requests_approval_workflow_id_foreign` (`approval_workflow_id`),
  KEY `approval_requests_submitted_by_foreign` (`submitted_by`),
  KEY `approval_requests_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `approval_requests_approval_workflow_id_foreign` FOREIGN KEY (`approval_workflow_id`) REFERENCES `approval_workflows` (`id`),
  CONSTRAINT `approval_requests_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `approval_requests_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approval_workflow_id` bigint unsigned NOT NULL,
  `level` tinyint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approver_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `project_role` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'any',
  `sla_hours` smallint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `approval_steps_approval_workflow_id_level_unique` (`approval_workflow_id`,`level`),
  KEY `approval_steps_role_id_foreign` (`role_id`),
  KEY `approval_steps_user_id_foreign` (`user_id`),
  CONSTRAINT `approval_steps_approval_workflow_id_foreign` FOREIGN KEY (`approval_workflow_id`) REFERENCES `approval_workflows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_steps_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `approval_steps_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_workflows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `min_amount` decimal(18,2) DEFAULT NULL,
  `max_amount` decimal(18,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_workflows_project_id_foreign` (`project_id`),
  KEY `approval_workflows_created_by_foreign` (`created_by`),
  KEY `approval_workflows_updated_by_foreign` (`updated_by`),
  KEY `approval_workflows_company_id_document_type_is_active_index` (`company_id`,`document_type`,`is_active`),
  CONSTRAINT `approval_workflows_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `approval_workflows_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_workflows_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `approval_workflows_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `attachable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachable_id` bigint unsigned NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attachments_company_id_foreign` (`company_id`),
  KEY `attachments_attachable_type_attachable_id_index` (`attachable_type`,`attachable_id`),
  KEY `attachments_uploaded_by_foreign` (`uploaded_by`),
  KEY `attachments_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `attachments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `attachments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `event` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_id` bigint unsigned NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `audit_logs_user_id_foreign` (`user_id`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audit_logs_company_id_created_at_index` (`company_id`,`created_at`),
  KEY `audit_logs_company_type_created_index` (`company_id`,`auditable_type`,`created_at`),
  KEY `audit_logs_company_user_created_index` (`company_id`,`user_id`,`created_at`),
  CONSTRAINT `audit_logs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bid_comparisons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bid_comparisons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `rfq_id` bigint unsigned NOT NULL,
  `selected_vendor_quotation_id` bigint unsigned DEFAULT NULL,
  `selection_basis` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `justification` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bid_comparisons_rfq_id_unique` (`rfq_id`),
  KEY `bid_comparisons_company_id_foreign` (`company_id`),
  KEY `bid_comparisons_selected_vendor_quotation_id_foreign` (`selected_vendor_quotation_id`),
  KEY `bid_comparisons_submitted_by_foreign` (`submitted_by`),
  KEY `bid_comparisons_approved_by_foreign` (`approved_by`),
  KEY `bid_comparisons_created_by_foreign` (`created_by`),
  KEY `bid_comparisons_updated_by_foreign` (`updated_by`),
  CONSTRAINT `bid_comparisons_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bid_comparisons_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `bid_comparisons_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bid_comparisons_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`),
  CONSTRAINT `bid_comparisons_selected_vendor_quotation_id_foreign` FOREIGN KEY (`selected_vendor_quotation_id`) REFERENCES `vendor_quotations` (`id`),
  CONSTRAINT `bid_comparisons_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bid_comparisons_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_bbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_bbs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned NOT NULL,
  `boq_line_uid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `drawing_revision_id` bigint unsigned DEFAULT NULL,
  `title` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `wastage_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `unit_weight_method` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'formula_d2_162',
  `steel_profile_id` bigint unsigned DEFAULT NULL,
  `bar_count_rounding` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ceil',
  `total_theoretical_weight` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `adjusted_theoretical_weight` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `wastage_weight` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `required_weight` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_weight_profile_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_bbs_boq_item_id_unique` (`boq_item_id`),
  KEY `boq_bbs_project_id_foreign` (`project_id`),
  KEY `boq_bbs_drawing_revision_id_foreign` (`drawing_revision_id`),
  KEY `boq_bbs_submitted_by_foreign` (`submitted_by`),
  KEY `boq_bbs_approved_by_foreign` (`approved_by`),
  KEY `boq_bbs_rejected_by_foreign` (`rejected_by`),
  KEY `boq_bbs_created_by_foreign` (`created_by`),
  KEY `boq_bbs_updated_by_foreign` (`updated_by`),
  KEY `boq_bbs_company_id_project_id_index` (`company_id`,`project_id`),
  KEY `boq_bbs_boq_id_status_index` (`boq_id`,`status`),
  KEY `boq_bbs_boq_line_uid_index` (`boq_line_uid`),
  KEY `boq_bbs_steel_profile_id_foreign` (`steel_profile_id`),
  CONSTRAINT `boq_bbs_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_bbs_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_bbs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boq_bbs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_drawing_revision_id_foreign` FOREIGN KEY (`drawing_revision_id`) REFERENCES `drawing_revisions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boq_bbs_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_steel_profile_id_foreign` FOREIGN KEY (`steel_profile_id`) REFERENCES `steel_unit_weight_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_bbs_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_bbs_adjustments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bbs_id` bigint unsigned NOT NULL,
  `diameter_mm` smallint unsigned DEFAULT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `adjustment_qty_kg` decimal(18,4) NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_bbs_adjustments_material_id_foreign` (`material_id`),
  KEY `boq_bbs_adjustments_created_by_foreign` (`created_by`),
  KEY `boq_bbs_adjustments_approved_by_foreign` (`approved_by`),
  KEY `boq_bbs_adjustments_bbs_id_index` (`bbs_id`),
  KEY `boq_bbs_adjustments_bbs_id_diameter_mm_index` (`bbs_id`,`diameter_mm`),
  CONSTRAINT `boq_bbs_adjustments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_adjustments_bbs_id_foreign` FOREIGN KEY (`bbs_id`) REFERENCES `boq_bbs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_bbs_adjustments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_adjustments_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_bbs_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_bbs_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bbs_id` bigint unsigned NOT NULL,
  `sequence` int unsigned NOT NULL DEFAULT '0',
  `structure_location` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bar_mark` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diameter_mm` smallint unsigned NOT NULL,
  `number_of_members` int unsigned NOT NULL DEFAULT '0',
  `bars_per_member` int unsigned NOT NULL DEFAULT '0',
  `count_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `distribution_length` decimal(18,4) DEFAULT NULL,
  `start_cover` decimal(18,4) DEFAULT NULL,
  `end_cover` decimal(18,4) DEFAULT NULL,
  `spacing` decimal(18,4) DEFAULT NULL,
  `include_end_bar` tinyint(1) NOT NULL DEFAULT '1',
  `faces_layers_multiplier` int unsigned NOT NULL DEFAULT '1',
  `bar_count_rounding` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_bars` int unsigned NOT NULL DEFAULT '0',
  `shape_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `a_length` decimal(18,4) DEFAULT NULL,
  `b_length` decimal(18,4) DEFAULT NULL,
  `c_length` decimal(18,4) DEFAULT NULL,
  `d_length` decimal(18,4) DEFAULT NULL,
  `e_length` decimal(18,4) DEFAULT NULL,
  `hook_length` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `bend_deduction` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `lap_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `lap_diameter_factor` decimal(18,4) DEFAULT NULL,
  `lap_length` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `lap_count_per_bar` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cutting_length` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `total_length` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_weight` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_weight_override` decimal(18,4) DEFAULT NULL,
  `unit_weight_override_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_weight_override_by` bigint unsigned DEFAULT NULL,
  `unit_weight_override_at` timestamp NULL DEFAULT NULL,
  `theoretical_weight` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_bbs_lines_bbs_id_sequence_index` (`bbs_id`,`sequence`),
  KEY `boq_bbs_lines_bbs_id_diameter_mm_index` (`bbs_id`,`diameter_mm`),
  KEY `boq_bbs_lines_unit_weight_override_by_foreign` (`unit_weight_override_by`),
  CONSTRAINT `boq_bbs_lines_bbs_id_foreign` FOREIGN KEY (`bbs_id`) REFERENCES `boq_bbs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_bbs_lines_unit_weight_override_by_foreign` FOREIGN KEY (`unit_weight_override_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_bbs_steel_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_bbs_steel_maps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bbs_id` bigint unsigned NOT NULL,
  `diameter_mm` smallint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_bbs_steel_maps_bbs_id_diameter_mm_unique` (`bbs_id`,`diameter_mm`),
  KEY `boq_bbs_steel_maps_created_by_foreign` (`created_by`),
  KEY `boq_bbs_steel_maps_updated_by_foreign` (`updated_by`),
  KEY `boq_bbs_steel_maps_material_id_index` (`material_id`),
  CONSTRAINT `boq_bbs_steel_maps_bbs_id_foreign` FOREIGN KEY (`bbs_id`) REFERENCES `boq_bbs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_bbs_steel_maps_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_bbs_steel_maps_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `boq_bbs_steel_maps_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_cost_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_cost_components` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned NOT NULL,
  `boq_line_uid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculation_basis` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quantity_rate',
  `consumption_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `consumption_unit` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `base_rate` decimal(18,4) DEFAULT NULL,
  `factor` decimal(18,4) DEFAULT NULL,
  `factor_reference` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `percent_value` decimal(7,4) DEFAULT NULL,
  `calculated_rate` decimal(18,4) DEFAULT NULL,
  `derivation_trace` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rate_library_id` bigint unsigned DEFAULT NULL,
  `conversion_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `wastage_percent` decimal(7,4) DEFAULT NULL,
  `amount_per_boq_unit` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `rate_source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sequence` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_cost_components_project_id_foreign` (`project_id`),
  KEY `boq_cost_components_boq_item_id_foreign` (`boq_item_id`),
  KEY `boq_cost_components_created_by_foreign` (`created_by`),
  KEY `boq_cost_components_updated_by_foreign` (`updated_by`),
  KEY `boq_cost_components_company_id_project_id_index` (`company_id`,`project_id`),
  KEY `boq_cost_components_boq_id_boq_item_id_index` (`boq_id`,`boq_item_id`),
  KEY `boq_cost_components_boq_line_uid_index` (`boq_line_uid`),
  KEY `boq_cost_components_component_type_index` (`component_type`),
  KEY `boq_cost_components_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `boq_cost_components_rate_library_id_foreign` (`rate_library_id`),
  CONSTRAINT `boq_cost_components_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_cost_components_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_cost_components_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boq_cost_components_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_cost_components_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boq_cost_components_rate_library_id_foreign` FOREIGN KEY (`rate_library_id`) REFERENCES `costing_rate_library_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_cost_components_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_drawing_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_drawing_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `boq_id` bigint unsigned NOT NULL,
  `drawing_revision_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_drawing_revisions_boq_id_drawing_revision_id_unique` (`boq_id`,`drawing_revision_id`),
  KEY `boq_drawing_revisions_drawing_revision_id_index` (`drawing_revision_id`),
  CONSTRAINT `boq_drawing_revisions_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_drawing_revisions_drawing_revision_id_foreign` FOREIGN KEY (`drawing_revision_id`) REFERENCES `drawing_revisions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_item_material_costs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_item_material_costs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `material_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sequence` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_item_material_costs_project_id_foreign` (`project_id`),
  KEY `boq_item_material_costs_boq_id_foreign` (`boq_id`),
  KEY `boq_item_material_costs_material_id_foreign` (`material_id`),
  KEY `boq_item_material_costs_created_by_foreign` (`created_by`),
  KEY `boq_item_material_costs_updated_by_foreign` (`updated_by`),
  KEY `boq_item_material_costs_boq_item_id_sequence_index` (`boq_item_id`,`sequence`),
  KEY `boq_item_material_costs_company_id_boq_id_index` (`company_id`,`boq_id`),
  CONSTRAINT `boq_item_material_costs_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_item_material_costs_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_item_material_costs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_item_material_costs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_item_material_costs_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_item_material_costs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_item_material_costs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `boq_id` bigint unsigned NOT NULL,
  `boq_section_id` bigint unsigned NOT NULL,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `line_uid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `hsn_sac` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `quantity_source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `measurement_mode` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `measurement_formula` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `measurement_nob` int unsigned NOT NULL DEFAULT '1',
  `measurement_nos` decimal(18,4) DEFAULT NULL,
  `measurement_length` decimal(18,4) DEFAULT NULL,
  `measurement_width` decimal(18,4) DEFAULT NULL,
  `measurement_height` decimal(18,4) DEFAULT NULL,
  `takeoff_reference` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bbs_applicable` tinyint(1) NOT NULL DEFAULT '0',
  `material_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `labour_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `equipment_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `subcontract_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cost_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cost_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `margin_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `selling_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `client_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `client_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `gst_rate` decimal(8,4) DEFAULT NULL,
  `rate_analysis_id` bigint unsigned DEFAULT NULL,
  `origin_variation_item_id` bigint unsigned DEFAULT NULL,
  `is_variation_originated` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `simple_materials_initialized` tinyint(1) NOT NULL DEFAULT '0',
  `concrete_mix_profile_id` bigint unsigned DEFAULT NULL,
  `concrete_mix_profile_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `source_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_import_id` bigint unsigned DEFAULT NULL,
  `source_sheet` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_row` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_items_boq_id_line_uid_unique` (`boq_id`,`line_uid`),
  KEY `boq_items_boq_section_id_foreign` (`boq_section_id`),
  KEY `boq_items_unit_id_foreign` (`unit_id`),
  KEY `boq_items_rate_analysis_id_foreign` (`rate_analysis_id`),
  KEY `boq_items_boq_id_boq_section_id_index` (`boq_id`,`boq_section_id`),
  KEY `boq_items_line_uid_index` (`line_uid`),
  KEY `boq_items_origin_variation_item_id_foreign` (`origin_variation_item_id`),
  KEY `boq_items_is_variation_originated_index` (`is_variation_originated`),
  KEY `boq_items_source_import_id_source_row_index` (`source_import_id`,`source_row`),
  KEY `boq_items_concrete_mix_profile_id_foreign` (`concrete_mix_profile_id`),
  KEY `boq_items_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `boq_items_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_items_boq_section_id_foreign` FOREIGN KEY (`boq_section_id`) REFERENCES `boq_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_items_concrete_mix_profile_id_foreign` FOREIGN KEY (`concrete_mix_profile_id`) REFERENCES `concrete_mix_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_items_origin_variation_item_id_foreign` FOREIGN KEY (`origin_variation_item_id`) REFERENCES `boq_variation_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_items_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_items_rate_analysis_id_foreign` FOREIGN KEY (`rate_analysis_id`) REFERENCES `rate_analyses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_items_source_import_id_foreign` FOREIGN KEY (`source_import_id`) REFERENCES `data_imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `boq_items_chk_1` CHECK (json_valid(`concrete_mix_profile_snapshot`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_material_norms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_material_norms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned NOT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `consumption_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `consumption_unit` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `wastage_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `notes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipe_template_id` bigint unsigned DEFAULT NULL,
  `recipe_version` int unsigned DEFAULT NULL,
  `recipe_code` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sequence` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_mat_norms_item_mat_unit_uq` (`boq_item_id`,`material_id`,`consumption_unit`),
  KEY `boq_material_norms_project_id_foreign` (`project_id`),
  KEY `boq_material_norms_created_by_foreign` (`created_by`),
  KEY `boq_material_norms_updated_by_foreign` (`updated_by`),
  KEY `boq_material_norms_company_id_project_id_index` (`company_id`,`project_id`),
  KEY `boq_material_norms_boq_id_boq_item_id_index` (`boq_id`,`boq_item_id`),
  KEY `boq_material_norms_boq_line_uid_index` (`boq_line_uid`),
  KEY `boq_material_norms_material_id_index` (`material_id`),
  KEY `boq_material_norms_recipe_template_id_foreign` (`recipe_template_id`),
  CONSTRAINT `boq_material_norms_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_material_norms_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_material_norms_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boq_material_norms_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_material_norms_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `boq_material_norms_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boq_material_norms_recipe_template_id_foreign` FOREIGN KEY (`recipe_template_id`) REFERENCES `material_recipe_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_material_norms_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_measurement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_measurement_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `boq_measurement_sheet_id` bigint unsigned NOT NULL,
  `location` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `formula_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nos` decimal(18,4) DEFAULT NULL,
  `length` decimal(18,4) DEFAULT NULL,
  `width` decimal(18,4) DEFAULT NULL,
  `height_depth` decimal(18,4) DEFAULT NULL,
  `diameter` decimal(18,4) DEFAULT NULL,
  `radius` decimal(18,4) DEFAULT NULL,
  `manual_quantity` decimal(18,4) DEFAULT NULL,
  `addition_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `deduction_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `calculated_base` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `calculated_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_id` bigint unsigned DEFAULT NULL,
  `drawing_revision_id` bigint unsigned DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_measurement_lines_unit_id_foreign` (`unit_id`),
  KEY `boq_measurement_lines_drawing_revision_id_foreign` (`drawing_revision_id`),
  KEY `boq_measurement_lines_boq_measurement_sheet_id_sort_order_index` (`boq_measurement_sheet_id`,`sort_order`),
  KEY `boq_measurement_lines_location_index` (`location`),
  CONSTRAINT `boq_measurement_lines_boq_measurement_sheet_id_foreign` FOREIGN KEY (`boq_measurement_sheet_id`) REFERENCES `boq_measurement_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_measurement_lines_drawing_revision_id_foreign` FOREIGN KEY (`drawing_revision_id`) REFERENCES `drawing_revisions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_measurement_lines_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_measurement_sheets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_measurement_sheets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned NOT NULL,
  `line_uid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `drawing_revision_id` bigint unsigned DEFAULT NULL,
  `title` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `total_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `circle_constant_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mathematical_pi',
  `circle_constant_value` decimal(18,4) DEFAULT NULL,
  `calculation_profile_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_measurement_sheets_project_id_foreign` (`project_id`),
  KEY `boq_measurement_sheets_boq_item_id_foreign` (`boq_item_id`),
  KEY `boq_measurement_sheets_drawing_revision_id_foreign` (`drawing_revision_id`),
  KEY `boq_measurement_sheets_approved_by_foreign` (`approved_by`),
  KEY `boq_measurement_sheets_rejected_by_foreign` (`rejected_by`),
  KEY `boq_measurement_sheets_created_by_foreign` (`created_by`),
  KEY `boq_measurement_sheets_updated_by_foreign` (`updated_by`),
  KEY `boq_measurement_sheets_company_id_project_id_index` (`company_id`,`project_id`),
  KEY `boq_measurement_sheets_boq_id_boq_item_id_index` (`boq_id`,`boq_item_id`),
  KEY `boq_measurement_sheets_line_uid_index` (`line_uid`),
  KEY `boq_measurement_sheets_status_index` (`status`),
  CONSTRAINT `boq_measurement_sheets_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_measurement_sheets_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_measurement_sheets_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_measurement_sheets_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boq_measurement_sheets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_measurement_sheets_drawing_revision_id_foreign` FOREIGN KEY (`drawing_revision_id`) REFERENCES `drawing_revisions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_measurement_sheets_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boq_measurement_sheets_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_measurement_sheets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_planning_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_planning_maps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned NOT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_task_id` bigint unsigned NOT NULL,
  `mapping_method` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'conversion_based',
  `tracking_basis` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allocated_boq_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `boq_unit_symbol` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `planning_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `planning_unit_symbol` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conversion_factor` decimal(18,4) DEFAULT NULL,
  `snapshot_boq_nos` decimal(18,4) DEFAULT NULL,
  `snapshot_boq_qty` decimal(18,4) DEFAULT NULL,
  `snapshot_boq_unit` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `snapshot_planning_unit` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sequence` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `needs_review` tinyint(1) NOT NULL DEFAULT '0',
  `review_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_planning_maps_boq_item_id_project_task_id_unique` (`boq_item_id`,`project_task_id`),
  KEY `boq_planning_maps_project_id_foreign` (`project_id`),
  KEY `boq_planning_maps_created_by_foreign` (`created_by`),
  KEY `boq_planning_maps_updated_by_foreign` (`updated_by`),
  KEY `boq_planning_maps_company_id_project_id_index` (`company_id`,`project_id`),
  KEY `boq_planning_maps_boq_id_boq_item_id_index` (`boq_id`,`boq_item_id`),
  KEY `boq_planning_maps_boq_line_uid_index` (`boq_line_uid`),
  KEY `boq_planning_maps_project_task_id_index` (`project_task_id`),
  KEY `bpm_task_primary_active_idx` (`project_task_id`,`is_primary`,`is_active`),
  CONSTRAINT `boq_planning_maps_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_planning_maps_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_planning_maps_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boq_planning_maps_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_planning_maps_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boq_planning_maps_project_task_id_foreign` FOREIGN KEY (`project_task_id`) REFERENCES `project_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_planning_maps_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_rate_comparison_quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_rate_comparison_quotes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned NOT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `vendor_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quoted_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `gst_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inclusive',
  `gst_percent` decimal(18,4) DEFAULT NULL,
  `effective_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `internal_cost_rate_snapshot` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `approved_boq_rate_snapshot` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `variance_vs_internal` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `variance_vs_internal_percent` decimal(7,4) DEFAULT NULL,
  `variance_vs_approved` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `variance_vs_approved_percent` decimal(7,4) DEFAULT NULL,
  `is_lowest` tinyint(1) NOT NULL DEFAULT '0',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sequence` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_rate_comparison_quotes_project_id_foreign` (`project_id`),
  KEY `boq_rate_comparison_quotes_boq_item_id_foreign` (`boq_item_id`),
  KEY `boq_rate_comparison_quotes_created_by_foreign` (`created_by`),
  KEY `boq_rate_comparison_quotes_updated_by_foreign` (`updated_by`),
  KEY `boq_rate_comparison_quotes_company_id_project_id_index` (`company_id`,`project_id`),
  KEY `boq_rate_comparison_quotes_boq_id_boq_item_id_index` (`boq_id`,`boq_item_id`),
  KEY `boq_rate_comparison_quotes_boq_line_uid_index` (`boq_line_uid`),
  KEY `boq_rate_comparison_quotes_vendor_id_index` (`vendor_id`),
  CONSTRAINT `boq_rate_comparison_quotes_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_rate_comparison_quotes_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_rate_comparison_quotes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boq_rate_comparison_quotes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_rate_comparison_quotes_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boq_rate_comparison_quotes_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_rate_comparison_quotes_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_revision_change_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_revision_change_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_boq_id` bigint unsigned NOT NULL,
  `to_boq_id` bigint unsigned NOT NULL,
  `change_order_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `revision_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `commercial_variation` decimal(18,2) NOT NULL DEFAULT '0.00',
  `internal_cost_variation` decimal(18,2) NOT NULL DEFAULT '0.00',
  `margin_variation` decimal(18,2) NOT NULL DEFAULT '0.00',
  `comparison_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `requested_by` bigint unsigned DEFAULT NULL,
  `prepared_by` bigint unsigned DEFAULT NULL,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_revision_change_orders_company_id_change_order_number_unique` (`company_id`,`change_order_number`),
  UNIQUE KEY `boq_revision_change_orders_from_boq_id_to_boq_id_unique` (`from_boq_id`,`to_boq_id`),
  KEY `boq_revision_change_orders_to_boq_id_foreign` (`to_boq_id`),
  KEY `boq_revision_change_orders_requested_by_foreign` (`requested_by`),
  KEY `boq_revision_change_orders_prepared_by_foreign` (`prepared_by`),
  KEY `boq_revision_change_orders_submitted_by_foreign` (`submitted_by`),
  KEY `boq_revision_change_orders_approved_by_foreign` (`approved_by`),
  KEY `boq_revision_change_orders_rejected_by_foreign` (`rejected_by`),
  KEY `boq_revision_change_orders_created_by_foreign` (`created_by`),
  KEY `boq_revision_change_orders_updated_by_foreign` (`updated_by`),
  KEY `boq_revision_change_orders_deleted_by_foreign` (`deleted_by`),
  KEY `boq_revision_change_orders_project_id_status_index` (`project_id`,`status`),
  KEY `boq_revision_change_orders_boq_number_status_index` (`boq_number`,`status`),
  CONSTRAINT `boq_revision_change_orders_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_revision_change_orders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boq_revision_change_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_revision_change_orders_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_revision_change_orders_from_boq_id_foreign` FOREIGN KEY (`from_boq_id`) REFERENCES `boqs` (`id`),
  CONSTRAINT `boq_revision_change_orders_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_revision_change_orders_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boq_revision_change_orders_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_revision_change_orders_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_revision_change_orders_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_revision_change_orders_to_boq_id_foreign` FOREIGN KEY (`to_boq_id`) REFERENCES `boqs` (`id`),
  CONSTRAINT `boq_revision_change_orders_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_revision_change_orders_chk_1` CHECK (json_valid(`comparison_snapshot`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `boq_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `discipline` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `default_project_cost_head_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_sections_parent_id_foreign` (`parent_id`),
  KEY `boq_sections_boq_id_parent_id_index` (`boq_id`,`parent_id`),
  KEY `boq_sections_default_project_cost_head_id_foreign` (`default_project_cost_head_id`),
  CONSTRAINT `boq_sections_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `boq_sections_default_project_cost_head_id_foreign` FOREIGN KEY (`default_project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_sections_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `boq_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_variation_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_variation_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `variation_id` bigint unsigned NOT NULL,
  `original_boq_item_id` bigint unsigned DEFAULT NULL,
  `original_line_uid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `variation_item_uid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `change_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hsn_sac` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `boq_section_id` bigint unsigned DEFAULT NULL,
  `quantity_source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `takeoff_reference` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `quantity_change` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `revised_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `original_selling_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `proposed_selling_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `original_cost_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `proposed_cost_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `gst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `revenue_impact` decimal(18,2) NOT NULL DEFAULT '0.00',
  `cost_impact` decimal(18,2) NOT NULL DEFAULT '0.00',
  `margin_impact` decimal(18,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `measurement_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `sequence` int unsigned NOT NULL DEFAULT '0',
  `materialized_boq_item_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_variation_items_variation_id_variation_item_uid_unique` (`variation_id`,`variation_item_uid`),
  KEY `boq_variation_items_unit_id_foreign` (`unit_id`),
  KEY `boq_variation_items_boq_section_id_foreign` (`boq_section_id`),
  KEY `boq_variation_items_materialized_boq_item_id_foreign` (`materialized_boq_item_id`),
  KEY `boq_variation_items_original_line_uid_index` (`original_line_uid`),
  KEY `boq_variation_items_original_boq_item_id_index` (`original_boq_item_id`),
  KEY `boq_variation_items_change_type_index` (`change_type`),
  CONSTRAINT `boq_variation_items_boq_section_id_foreign` FOREIGN KEY (`boq_section_id`) REFERENCES `boq_sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_variation_items_materialized_boq_item_id_foreign` FOREIGN KEY (`materialized_boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_variation_items_original_boq_item_id_foreign` FOREIGN KEY (`original_boq_item_id`) REFERENCES `boq_items` (`id`),
  CONSTRAINT `boq_variation_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `boq_variation_items_variation_id_foreign` FOREIGN KEY (`variation_id`) REFERENCES `boq_variations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_variations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_variations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `variation_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `change_order_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scope_change',
  `source_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_reference` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `drawing_revision_id` bigint unsigned DEFAULT NULL,
  `client_instruction_reference` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_instruction_date` date DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `revenue_impact` decimal(18,2) NOT NULL DEFAULT '0.00',
  `cost_impact` decimal(18,2) NOT NULL DEFAULT '0.00',
  `margin_impact` decimal(18,2) NOT NULL DEFAULT '0.00',
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boq_variations_company_id_variation_number_unique` (`company_id`,`variation_number`),
  KEY `boq_variations_drawing_revision_id_foreign` (`drawing_revision_id`),
  KEY `boq_variations_submitted_by_foreign` (`submitted_by`),
  KEY `boq_variations_approved_by_foreign` (`approved_by`),
  KEY `boq_variations_rejected_by_foreign` (`rejected_by`),
  KEY `boq_variations_created_by_foreign` (`created_by`),
  KEY `boq_variations_updated_by_foreign` (`updated_by`),
  KEY `boq_variations_deleted_by_foreign` (`deleted_by`),
  KEY `boq_variations_project_id_status_index` (`project_id`,`status`),
  KEY `boq_variations_boq_id_status_index` (`boq_id`,`status`),
  KEY `boq_variations_company_id_project_id_status_index` (`company_id`,`project_id`,`status`),
  CONSTRAINT `boq_variations_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_variations_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`),
  CONSTRAINT `boq_variations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boq_variations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_variations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_variations_drawing_revision_id_foreign` FOREIGN KEY (`drawing_revision_id`) REFERENCES `drawing_revisions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_variations_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boq_variations_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_variations_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boq_variations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boqs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boqs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `boq_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` smallint unsigned NOT NULL DEFAULT '1',
  `parent_boq_id` bigint unsigned DEFAULT NULL,
  `source_import_id` bigint unsigned DEFAULT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `total_cost_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_client_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `technical_approved_at` timestamp NULL DEFAULT NULL,
  `technical_approved_by` bigint unsigned DEFAULT NULL,
  `technical_approval_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `technical_snapshot_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `boqs_company_id_boq_number_version_unique` (`company_id`,`boq_number`,`version`),
  KEY `boqs_parent_boq_id_foreign` (`parent_boq_id`),
  KEY `boqs_approved_by_foreign` (`approved_by`),
  KEY `boqs_created_by_foreign` (`created_by`),
  KEY `boqs_updated_by_foreign` (`updated_by`),
  KEY `boqs_deleted_by_foreign` (`deleted_by`),
  KEY `boqs_project_id_is_current_index` (`project_id`,`is_current`),
  KEY `boqs_company_id_status_index` (`company_id`,`status`),
  KEY `boqs_source_import_id_foreign` (`source_import_id`),
  KEY `boqs_technical_approved_by_foreign` (`technical_approved_by`),
  CONSTRAINT `boqs_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boqs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `boqs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boqs_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boqs_parent_boq_id_foreign` FOREIGN KEY (`parent_boq_id`) REFERENCES `boqs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boqs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `boqs_source_import_id_foreign` FOREIGN KEY (`source_import_id`) REFERENCES `data_imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boqs_technical_approved_by_foreign` FOREIGN KEY (`technical_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boqs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `boqs_chk_1` CHECK (json_valid(`technical_approval_snapshot`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoice_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_invoice_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `boq_qty` decimal(18,4) NOT NULL,
  `executed_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `previous_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `current_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cumulative_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `rate` decimal(18,4) NOT NULL,
  `current_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `is_override` tinyint(1) NOT NULL DEFAULT '0',
  `override_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_invoice_items_line_unique` (`client_invoice_id`,`boq_line_uid`),
  KEY `client_invoice_items_boq_item_id_foreign` (`boq_item_id`),
  KEY `client_invoice_items_unit_id_foreign` (`unit_id`),
  KEY `client_invoice_items_boq_line_uid_index` (`boq_line_uid`),
  CONSTRAINT `client_invoice_items_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_invoice_items_client_invoice_id_foreign` FOREIGN KEY (`client_invoice_id`) REFERENCES `client_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `client_invoice_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `client_id` bigint unsigned NOT NULL,
  `boq_id` bigint unsigned NOT NULL,
  `invoice_number` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ra_sequence` int unsigned NOT NULL,
  `invoice_date` date NOT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `supplier_state` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `place_of_supply_state` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `gross_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `cgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `igst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `invoice_total` decimal(18,2) NOT NULL DEFAULT '0.00',
  `retention_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `retention_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `advance_recovery` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tds_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `tds_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `other_deductions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `net_payable` decimal(18,2) NOT NULL DEFAULT '0.00',
  `received_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `certified_by` bigint unsigned DEFAULT NULL,
  `certified_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_invoices_project_id_ra_sequence_unique` (`project_id`,`ra_sequence`),
  UNIQUE KEY `client_invoices_company_id_invoice_number_unique` (`company_id`,`invoice_number`),
  KEY `client_invoices_boq_id_foreign` (`boq_id`),
  KEY `client_invoices_tax_rate_id_foreign` (`tax_rate_id`),
  KEY `client_invoices_certified_by_foreign` (`certified_by`),
  KEY `client_invoices_created_by_foreign` (`created_by`),
  KEY `client_invoices_updated_by_foreign` (`updated_by`),
  KEY `client_invoices_deleted_by_foreign` (`deleted_by`),
  KEY `client_invoices_project_id_status_index` (`project_id`,`status`),
  KEY `client_invoices_client_id_index` (`client_id`),
  CONSTRAINT `client_invoices_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`),
  CONSTRAINT `client_invoices_certified_by_foreign` FOREIGN KEY (`certified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_invoices_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  CONSTRAINT `client_invoices_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `client_invoices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_invoices_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_invoices_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `client_invoices_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`),
  CONSTRAINT `client_invoices_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gstin` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_address` text COLLATE utf8mb4_unicode_ci,
  `shipping_address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clients_company_id_code_unique` (`company_id`,`code`),
  KEY `clients_created_by_foreign` (`created_by`),
  KEY `clients_updated_by_foreign` (`updated_by`),
  KEY `clients_deleted_by_foreign` (`deleted_by`),
  KEY `clients_company_id_company_name_index` (`company_id`,`company_name`),
  CONSTRAINT `clients_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `clients_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `clients_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `clients_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `legal_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gstin` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `favicon_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `fy_start_month` tinyint unsigned NOT NULL DEFAULT '4',
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Asia/Kolkata',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `companies_code_unique` (`code`),
  KEY `companies_created_by_foreign` (`created_by`),
  KEY `companies_updated_by_foreign` (`updated_by`),
  KEY `companies_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `companies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `companies_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `companies_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_settings_company_id_key_unique` (`company_id`,`key`),
  CONSTRAINT `company_settings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_user` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `user_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff',
  `party_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `party_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `menu_items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_user_company_id_user_id_unique` (`company_id`,`user_id`),
  KEY `company_user_user_id_foreign` (`user_id`),
  KEY `company_user_party_type_party_id_index` (`party_type`,`party_id`),
  KEY `company_user_created_by_foreign` (`created_by`),
  KEY `company_user_updated_by_foreign` (`updated_by`),
  CONSTRAINT `company_user_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `company_user_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `company_user_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `company_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_user_chk_1` CHECK (json_valid(`menu_items`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `concrete_mix_profile_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `concrete_mix_profile_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `concrete_mix_profile_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `material_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `factor` decimal(18,4) NOT NULL,
  `factor_unit` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `concrete_mix_profile_lines_material_id_foreign` (`material_id`),
  KEY `cmp_lines_profile_sort_idx` (`concrete_mix_profile_id`,`sort_order`),
  CONSTRAINT `concrete_mix_profile_lines_concrete_mix_profile_id_foreign` FOREIGN KEY (`concrete_mix_profile_id`) REFERENCES `concrete_mix_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `concrete_mix_profile_lines_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `concrete_mix_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `concrete_mix_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `concrete_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grade` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `boq_unit_symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Cum',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cmp_company_name_uq` (`company_id`,`name`),
  KEY `concrete_mix_profiles_created_by_foreign` (`created_by`),
  KEY `concrete_mix_profiles_updated_by_foreign` (`updated_by`),
  KEY `cmp_company_active_sort_idx` (`company_id`,`is_active`,`sort_order`),
  CONSTRAINT `concrete_mix_profiles_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `concrete_mix_profiles_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `concrete_mix_profiles_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conversation_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conversation_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `last_read_message_id` bigint unsigned DEFAULT NULL,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `viewing_at` timestamp NULL DEFAULT NULL,
  `joined_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversation_participants_user_id_conversation_id_unique` (`user_id`,`conversation_id`),
  KEY `conversation_participants_conversation_id_foreign` (`conversation_id`),
  KEY `conversation_participants_last_read_message_id_foreign` (`last_read_message_id`),
  CONSTRAINT `conversation_participants_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversation_participants_last_read_message_id_foreign` FOREIGN KEY (`last_read_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `conversation_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `pair_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversations_company_id_pair_key_unique` (`company_id`,`pair_key`),
  KEY `conversations_created_by_foreign` (`created_by`),
  KEY `conversations_company_id_updated_at_index` (`company_id`,`updated_at`),
  CONSTRAINT `conversations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `conversations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `costing_rate_library_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `costing_rate_library_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `entry_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rate` decimal(18,4) NOT NULL,
  `effective_from` date DEFAULT NULL,
  `source` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `costing_rate_library_entries_created_by_foreign` (`created_by`),
  KEY `costing_rate_library_entries_updated_by_foreign` (`updated_by`),
  KEY `rate_lib_co_type_active_idx` (`company_id`,`entry_type`,`is_active`),
  KEY `rate_lib_proj_active_idx` (`project_id`,`is_active`),
  CONSTRAINT `costing_rate_library_entries_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `costing_rate_library_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `costing_rate_library_entries_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `costing_rate_library_entries_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `module` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'create',
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `disk` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'private',
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `validation_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_report_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_rows` int unsigned NOT NULL DEFAULT '0',
  `valid_rows` int unsigned NOT NULL DEFAULT '0',
  `invalid_rows` int unsigned NOT NULL DEFAULT '0',
  `duplicate_rows` int unsigned NOT NULL DEFAULT '0',
  `create_count` int unsigned NOT NULL DEFAULT '0',
  `update_count` int unsigned NOT NULL DEFAULT '0',
  `skip_count` int unsigned NOT NULL DEFAULT '0',
  `fail_count` int unsigned NOT NULL DEFAULT '0',
  `failure_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `data_imports_uuid_unique` (`uuid`),
  KEY `data_imports_project_id_foreign` (`project_id`),
  KEY `data_imports_uploaded_by_foreign` (`uploaded_by`),
  KEY `data_imports_company_id_module_status_index` (`company_id`,`module`,`status`),
  KEY `data_imports_company_id_uploaded_by_created_at_index` (`company_id`,`uploaded_by`,`created_at`),
  CONSTRAINT `data_imports_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `data_imports_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `data_imports_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_company_id_code_unique` (`company_id`,`code`),
  KEY `departments_created_by_foreign` (`created_by`),
  KEY `departments_updated_by_foreign` (`updated_by`),
  CONSTRAINT `departments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `departments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `departments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `platform` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_tokens_token_unique` (`token`),
  KEY `device_tokens_user_id_index` (`user_id`),
  CONSTRAINT `device_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_folders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `parent_key` bigint unsigned GENERATED ALWAYS AS (coalesce(`parent_id`,0)) STORED,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_folders_project_id_parent_key_name_unique` (`project_id`,`parent_key`,`name`),
  KEY `document_folders_company_id_foreign` (`company_id`),
  KEY `document_folders_created_by_foreign` (`created_by`),
  KEY `document_folders_updated_by_foreign` (`updated_by`),
  KEY `document_folders_parent_id_index` (`parent_id`),
  CONSTRAINT `document_folders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `document_folders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_folders_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `document_folders` (`id`),
  CONSTRAINT `document_folders_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `document_folders_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_number_formats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_number_formats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pattern` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reset_frequency` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'never',
  `assign_on` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'create',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_number_formats_company_id_document_type_unique` (`company_id`,`document_type`),
  CONSTRAINT `document_number_formats_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `period_key` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_number` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_sequences_unique` (`company_id`,`document_type`,`scope_key`,`period_key`),
  CONSTRAINT `document_sequences_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_terms_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_terms_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_profile` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contractor_scope` text COLLATE utf8mb4_unicode_ci,
  `company_scope` text COLLATE utf8mb4_unicode_ci,
  `payment_terms` text COLLATE utf8mb4_unicode_ci,
  `safety_terms` text COLLATE utf8mb4_unicode_ci,
  `general_terms` text COLLATE utf8mb4_unicode_ci,
  `penalty_terms` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `doc_terms_company_type_active` (`company_id`,`document_type`,`is_active`),
  CONSTRAINT `document_terms_templates_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `version_no` int unsigned NOT NULL,
  `revision_label` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_versions_document_id_version_no_unique` (`document_id`,`version_no`),
  KEY `document_versions_company_id_foreign` (`company_id`),
  KEY `document_versions_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `document_versions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `document_versions_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `document_versions_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `document_versions_immutable` BEFORE UPDATE ON `document_versions` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document versions are immutable.';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `document_versions_no_delete` BEFORE DELETE ON `document_versions` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document versions are never deleted.';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `document_folder_id` bigint unsigned DEFAULT NULL,
  `document_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_version_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived_by` bigint unsigned DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `documents_company_id_document_number_unique` (`company_id`,`document_number`),
  KEY `documents_document_folder_id_foreign` (`document_folder_id`),
  KEY `documents_archived_by_foreign` (`archived_by`),
  KEY `documents_created_by_foreign` (`created_by`),
  KEY `documents_updated_by_foreign` (`updated_by`),
  KEY `documents_deleted_by_foreign` (`deleted_by`),
  KEY `documents_project_id_document_folder_id_index` (`project_id`,`document_folder_id`),
  KEY `documents_project_id_status_index` (`project_id`,`status`),
  KEY `documents_current_version_id_foreign` (`current_version_id`),
  CONSTRAINT `documents_archived_by_foreign` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_current_version_id_foreign` FOREIGN KEY (`current_version_id`) REFERENCES `document_versions` (`id`),
  CONSTRAINT `documents_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_document_folder_id_foreign` FOREIGN KEY (`document_folder_id`) REFERENCES `document_folders` (`id`),
  CONSTRAINT `documents_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `documents_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dpr_constraints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dpr_constraints` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dpr_id` bigint unsigned NOT NULL,
  `category` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_area` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `responsible` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raised_date` date DEFAULT NULL,
  `expected_resolution` date DEFAULT NULL,
  `status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dpr_constraints_task_id_foreign` (`task_id`),
  KEY `dpr_constraints_status` (`dpr_id`,`status`),
  CONSTRAINT `dpr_constraints_dpr_id_foreign` FOREIGN KEY (`dpr_id`) REFERENCES `dprs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dpr_constraints_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dpr_equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dpr_equipment` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dpr_id` bigint unsigned NOT NULL,
  `equipment_id` bigint unsigned DEFAULT NULL,
  `equipment_type_id` bigint unsigned DEFAULT NULL,
  `description` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `duration_hours` decimal(8,2) DEFAULT NULL,
  `working_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `idle_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_informational` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dpr_equipment_dpr_id_foreign` (`dpr_id`),
  KEY `dpr_equipment_equipment_type_id_foreign` (`equipment_type_id`),
  KEY `dpr_equipment_equipment_id_index` (`equipment_id`),
  CONSTRAINT `dpr_equipment_dpr_id_foreign` FOREIGN KEY (`dpr_id`) REFERENCES `dprs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dpr_equipment_equipment_type_id_foreign` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dpr_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dpr_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dpr_id` bigint unsigned NOT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_area` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activity_name_snapshot` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_snapshot` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `planned_start_date` date DEFAULT NULL,
  `planned_end_date` date DEFAULT NULL,
  `planned_qty` decimal(18,4) DEFAULT NULL,
  `previous_executed_qty` decimal(18,4) DEFAULT NULL,
  `today_target_qty` decimal(18,4) DEFAULT NULL,
  `executed_qty` decimal(18,4) NOT NULL,
  `boq_executed_qty` decimal(18,4) DEFAULT NULL,
  `suggested_boq_qty` decimal(18,4) DEFAULT NULL,
  `hold_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `hold_reason` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hold_since` date DEFAULT NULL,
  `hold_responsible` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hold_expected_resolution` date DEFAULT NULL,
  `hold_status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cumulative_qty` decimal(18,4) DEFAULT NULL,
  `balance_qty` decimal(18,4) DEFAULT NULL,
  `target_variance` decimal(18,4) DEFAULT NULL,
  `achievement_percent` decimal(8,4) DEFAULT NULL,
  `completion_percent` decimal(8,4) DEFAULT NULL,
  `shortfall_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `on_hold` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dpr_items_dpr_id_foreign` (`dpr_id`),
  KEY `dpr_items_boq_item_id_foreign` (`boq_item_id`),
  KEY `dpr_items_unit_id_foreign` (`unit_id`),
  KEY `dpr_items_task_id_index` (`task_id`),
  KEY `dpr_items_boq_line_uid_index` (`boq_line_uid`),
  CONSTRAINT `dpr_items_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`),
  CONSTRAINT `dpr_items_dpr_id_foreign` FOREIGN KEY (`dpr_id`) REFERENCES `dprs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dpr_items_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`),
  CONSTRAINT `dpr_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dpr_labours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dpr_labours` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dpr_id` bigint unsigned NOT NULL,
  `labour_trade_id` bigint unsigned NOT NULL,
  `subcontractor_id` bigint unsigned DEFAULT NULL,
  `headcount` int unsigned NOT NULL,
  `skilled` int unsigned DEFAULT NULL,
  `unskilled` int unsigned DEFAULT NULL,
  `operator_count` int unsigned DEFAULT NULL,
  `helper_count` int unsigned DEFAULT NULL,
  `hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_informational` tinyint(1) NOT NULL DEFAULT '1',
  `source` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dpr_labours_dpr_id_foreign` (`dpr_id`),
  KEY `dpr_labours_labour_trade_id_foreign` (`labour_trade_id`),
  KEY `dpr_labours_subcontractor_id_foreign` (`subcontractor_id`),
  CONSTRAINT `dpr_labours_dpr_id_foreign` FOREIGN KEY (`dpr_id`) REFERENCES `dprs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dpr_labours_labour_trade_id_foreign` FOREIGN KEY (`labour_trade_id`) REFERENCES `labour_trades` (`id`),
  CONSTRAINT `dpr_labours_subcontractor_id_foreign` FOREIGN KEY (`subcontractor_id`) REFERENCES `subcontractors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dpr_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dpr_materials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dpr_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `opening_qty` decimal(18,4) DEFAULT NULL,
  `received_qty` decimal(18,4) DEFAULT NULL,
  `issued_qty` decimal(18,4) DEFAULT NULL,
  `closing_qty` decimal(18,4) DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_informational` tinyint(1) NOT NULL DEFAULT '0',
  `source` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inventory',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dpr_materials_dpr_id_foreign` (`dpr_id`),
  KEY `dpr_materials_material_id_foreign` (`material_id`),
  KEY `dpr_materials_unit_id_foreign` (`unit_id`),
  CONSTRAINT `dpr_materials_dpr_id_foreign` FOREIGN KEY (`dpr_id`) REFERENCES `dprs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dpr_materials_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `dpr_materials_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dprs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dprs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `dpr_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dpr_date` date NOT NULL,
  `weather` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_issues` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `source` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `work_performed` text COLLATE utf8mb4_unicode_ci,
  `constraints_summary` text COLLATE utf8mb4_unicode_ci,
  `department` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agency_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engineer_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `revision` smallint unsigned NOT NULL DEFAULT '0',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `reopened_by` bigint unsigned DEFAULT NULL,
  `reopened_at` timestamp NULL DEFAULT NULL,
  `reopen_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pdf_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dprs_project_id_dpr_date_unique` (`project_id`,`dpr_date`),
  UNIQUE KEY `dprs_company_id_dpr_number_unique` (`company_id`,`dpr_number`),
  KEY `dprs_engineer_id_foreign` (`engineer_id`),
  KEY `dprs_approved_by_foreign` (`approved_by`),
  KEY `dprs_reopened_by_foreign` (`reopened_by`),
  KEY `dprs_created_by_foreign` (`created_by`),
  KEY `dprs_updated_by_foreign` (`updated_by`),
  KEY `dprs_deleted_by_foreign` (`deleted_by`),
  KEY `dprs_project_id_status_index` (`project_id`,`status`),
  KEY `dprs_site_id_foreign` (`site_id`),
  CONSTRAINT `dprs_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dprs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `dprs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dprs_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dprs_engineer_id_foreign` FOREIGN KEY (`engineer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dprs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `dprs_reopened_by_foreign` FOREIGN KEY (`reopened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dprs_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dprs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `drawing_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `drawing_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `drawing_id` bigint unsigned NOT NULL,
  `revision_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `disk` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `decided_by` bigint unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `review_comments` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supersedes_revision_id` bigint unsigned DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `drawing_revisions_drawing_id_revision_code_unique` (`drawing_id`,`revision_code`),
  KEY `drawing_revisions_company_id_foreign` (`company_id`),
  KEY `drawing_revisions_uploaded_by_foreign` (`uploaded_by`),
  KEY `drawing_revisions_submitted_by_foreign` (`submitted_by`),
  KEY `drawing_revisions_reviewed_by_foreign` (`reviewed_by`),
  KEY `drawing_revisions_decided_by_foreign` (`decided_by`),
  KEY `drawing_revisions_supersedes_revision_id_foreign` (`supersedes_revision_id`),
  KEY `drawing_revisions_created_by_foreign` (`created_by`),
  KEY `drawing_revisions_updated_by_foreign` (`updated_by`),
  KEY `drawing_revisions_deleted_by_foreign` (`deleted_by`),
  KEY `drawing_revisions_drawing_id_status_index` (`drawing_id`,`status`),
  CONSTRAINT `drawing_revisions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `drawing_revisions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `drawing_revisions_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `drawing_revisions_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `drawing_revisions_drawing_id_foreign` FOREIGN KEY (`drawing_id`) REFERENCES `drawings` (`id`),
  CONSTRAINT `drawing_revisions_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `drawing_revisions_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `drawing_revisions_supersedes_revision_id_foreign` FOREIGN KEY (`supersedes_revision_id`) REFERENCES `drawing_revisions` (`id`),
  CONSTRAINT `drawing_revisions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `drawing_revisions_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `drawing_revisions_immutable` BEFORE UPDATE ON `drawing_revisions` FOR EACH ROW BEGIN
    IF NOT (NEW.drawing_id <=> OLD.drawing_id AND NEW.revision_code <=> OLD.revision_code AND NEW.disk <=> OLD.disk
        AND NEW.file_path <=> OLD.file_path AND NEW.file_name <=> OLD.file_name AND NEW.mime <=> OLD.mime
        AND NEW.size_bytes <=> OLD.size_bytes AND NEW.checksum <=> OLD.checksum) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Drawing revision files are immutable.';
    END IF;
    IF OLD.status = 'superseded' OR (OLD.status = 'approved' AND NEW.status <> 'superseded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An approved drawing revision cannot be changed.';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `drawing_revisions_no_delete` BEFORE DELETE ON `drawing_revisions` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Drawing revisions are never deleted.';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `drawings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `drawings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `drawing_number` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `discipline` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_revision_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `drawings_project_id_drawing_number_unique` (`project_id`,`drawing_number`),
  KEY `drawings_company_id_foreign` (`company_id`),
  KEY `drawings_created_by_foreign` (`created_by`),
  KEY `drawings_updated_by_foreign` (`updated_by`),
  KEY `drawings_deleted_by_foreign` (`deleted_by`),
  KEY `drawings_project_id_discipline_index` (`project_id`,`discipline`),
  KEY `drawings_current_revision_id_foreign` (`current_revision_id`),
  CONSTRAINT `drawings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `drawings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `drawings_current_revision_id_foreign` FOREIGN KEY (`current_revision_id`) REFERENCES `drawing_revisions` (`id`),
  CONSTRAINT `drawings_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `drawings_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `drawings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_bill_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_bill_components` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `electricity_bill_id` bigint unsigned NOT NULL,
  `tariff_component_id` bigint unsigned DEFAULT NULL,
  `component_code` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculation_basis` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(18,4) DEFAULT NULL,
  `rate` decimal(18,4) DEFAULT NULL,
  `percentage` decimal(7,4) DEFAULT NULL,
  `calculated_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `actual_amount` decimal(18,2) DEFAULT NULL,
  `variance_amount` decimal(18,2) DEFAULT NULL,
  `is_rebate` tinyint(1) NOT NULL DEFAULT '0',
  `is_tax` tinyint(1) NOT NULL DEFAULT '0',
  `is_penalty` tinyint(1) NOT NULL DEFAULT '0',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `elec_bill_comp_tariff_fk` (`tariff_component_id`),
  KEY `elec_bill_comp_sort` (`electricity_bill_id`,`sort_order`),
  CONSTRAINT `elec_bill_comp_bill_fk` FOREIGN KEY (`electricity_bill_id`) REFERENCES `electricity_bills` (`id`) ON DELETE CASCADE,
  CONSTRAINT `elec_bill_comp_tariff_fk` FOREIGN KEY (`tariff_component_id`) REFERENCES `electricity_tariff_components` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_bills` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `utility_site_id` bigint unsigned NOT NULL,
  `electricity_connection_id` bigint unsigned NOT NULL,
  `electricity_meter_reading_id` bigint unsigned NOT NULL,
  `electricity_tariff_id` bigint unsigned DEFAULT NULL,
  `bill_number` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `billing_month` date NOT NULL,
  `bill_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_total` decimal(18,2) NOT NULL DEFAULT '0.00',
  `actual_bill_total` decimal(18,2) DEFAULT NULL,
  `variance_total` decimal(18,2) DEFAULT NULL,
  `cost_per_kwh` decimal(18,4) DEFAULT NULL,
  `cost_per_kvah` decimal(18,4) DEFAULT NULL,
  `demand_utilization_percent` decimal(7,4) DEFAULT NULL,
  `excess_demand` decimal(18,4) DEFAULT NULL,
  `tariff_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `reading_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `import_source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_batch_id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_sheet` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_row` int unsigned DEFAULT NULL,
  `source_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `electricity_bills_company_id_bill_number_unique` (`company_id`,`bill_number`),
  UNIQUE KEY `elec_bill_reading_unique` (`electricity_meter_reading_id`),
  KEY `electricity_bills_utility_site_id_foreign` (`utility_site_id`),
  KEY `electricity_bills_electricity_connection_id_foreign` (`electricity_connection_id`),
  KEY `elec_bill_tariff_fk` (`electricity_tariff_id`),
  KEY `electricity_bills_reviewed_by_foreign` (`reviewed_by`),
  KEY `electricity_bills_approved_by_foreign` (`approved_by`),
  KEY `electricity_bills_created_by_foreign` (`created_by`),
  KEY `electricity_bills_updated_by_foreign` (`updated_by`),
  KEY `electricity_bills_deleted_by_foreign` (`deleted_by`),
  KEY `elec_bill_company_month_status` (`company_id`,`billing_month`,`status`),
  KEY `elec_bill_import_batch` (`company_id`,`import_batch_id`),
  CONSTRAINT `elec_bill_reading_fk` FOREIGN KEY (`electricity_meter_reading_id`) REFERENCES `electricity_meter_readings` (`id`),
  CONSTRAINT `elec_bill_tariff_fk` FOREIGN KEY (`electricity_tariff_id`) REFERENCES `electricity_tariffs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_bills_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_bills_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `electricity_bills_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_bills_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_bills_electricity_connection_id_foreign` FOREIGN KEY (`electricity_connection_id`) REFERENCES `electricity_connections` (`id`),
  CONSTRAINT `electricity_bills_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_bills_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_bills_utility_site_id_foreign` FOREIGN KEY (`utility_site_id`) REFERENCES `utility_sites` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_connections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `utility_site_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `connection_number` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `consumer_number` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discom_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tariff_category` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voltage_level` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sanctioned_demand` decimal(18,4) DEFAULT NULL,
  `contract_demand` decimal(18,4) DEFAULT NULL,
  `demand_unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kVA',
  `metering_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tod_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `kwh_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `kvah_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `default_multiplication_factor` decimal(18,4) NOT NULL DEFAULT '1.0000',
  `billing_cycle` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `low_pf_threshold` decimal(8,4) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `electricity_connections_company_id_connection_number_unique` (`company_id`,`connection_number`),
  KEY `electricity_connections_utility_site_id_foreign` (`utility_site_id`),
  KEY `electricity_connections_vendor_id_foreign` (`vendor_id`),
  KEY `electricity_connections_created_by_foreign` (`created_by`),
  KEY `electricity_connections_updated_by_foreign` (`updated_by`),
  KEY `electricity_connections_deleted_by_foreign` (`deleted_by`),
  KEY `elec_conn_company_site_active` (`company_id`,`utility_site_id`,`is_active`),
  CONSTRAINT `electricity_connections_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `electricity_connections_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_connections_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_connections_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_connections_utility_site_id_foreign` FOREIGN KEY (`utility_site_id`) REFERENCES `utility_sites` (`id`),
  CONSTRAINT `electricity_connections_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_meter_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_meter_readings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `utility_site_id` bigint unsigned NOT NULL,
  `electricity_connection_id` bigint unsigned NOT NULL,
  `electricity_meter_id` bigint unsigned NOT NULL,
  `billing_month` date NOT NULL,
  `from_date` date NOT NULL,
  `to_date` date NOT NULL,
  `opening_kwh` decimal(18,4) DEFAULT NULL,
  `closing_kwh` decimal(18,4) DEFAULT NULL,
  `opening_kvah` decimal(18,4) DEFAULT NULL,
  `closing_kvah` decimal(18,4) DEFAULT NULL,
  `mdi_reading` decimal(18,4) DEFAULT NULL,
  `multiplication_factor` decimal(18,4) NOT NULL DEFAULT '1.0000',
  `days` smallint unsigned NOT NULL DEFAULT '0',
  `kwh_consumption` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `kvah_consumption` decimal(18,4) DEFAULT NULL,
  `billing_demand` decimal(18,4) DEFAULT NULL,
  `contract_demand_snapshot` decimal(18,4) DEFAULT NULL,
  `demand_utilization_percent` decimal(7,4) DEFAULT NULL,
  `power_factor` decimal(10,6) DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` smallint unsigned NOT NULL DEFAULT '0',
  `is_historical_aggregate` tinyint(1) NOT NULL DEFAULT '0',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `import_source` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_batch_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_sheet` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_row` int unsigned DEFAULT NULL,
  `source_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_by` bigint unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `elec_reading_meter_month_rev` (`electricity_meter_id`,`billing_month`,`revision`),
  KEY `electricity_meter_readings_utility_site_id_foreign` (`utility_site_id`),
  KEY `electricity_meter_readings_electricity_connection_id_foreign` (`electricity_connection_id`),
  KEY `electricity_meter_readings_confirmed_by_foreign` (`confirmed_by`),
  KEY `electricity_meter_readings_created_by_foreign` (`created_by`),
  KEY `electricity_meter_readings_updated_by_foreign` (`updated_by`),
  KEY `electricity_meter_readings_deleted_by_foreign` (`deleted_by`),
  KEY `elec_reading_company_month_status` (`company_id`,`billing_month`,`status`),
  KEY `elec_reading_import_batch` (`company_id`,`import_batch_id`),
  KEY `elec_reading_source_hash_month` (`company_id`,`source_hash`,`billing_month`),
  CONSTRAINT `electricity_meter_readings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `electricity_meter_readings_confirmed_by_foreign` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_meter_readings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_meter_readings_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_meter_readings_electricity_connection_id_foreign` FOREIGN KEY (`electricity_connection_id`) REFERENCES `electricity_connections` (`id`),
  CONSTRAINT `electricity_meter_readings_electricity_meter_id_foreign` FOREIGN KEY (`electricity_meter_id`) REFERENCES `electricity_meters` (`id`),
  CONSTRAINT `electricity_meter_readings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_meter_readings_utility_site_id_foreign` FOREIGN KEY (`utility_site_id`) REFERENCES `utility_sites` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_meter_tod_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_meter_tod_readings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `electricity_meter_reading_id` bigint unsigned NOT NULL,
  `slot_code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slot_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opening` decimal(18,4) DEFAULT NULL,
  `closing` decimal(18,4) DEFAULT NULL,
  `units` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `elec_tod_reading_slot` (`electricity_meter_reading_id`,`slot_code`),
  CONSTRAINT `elec_tod_reading_fk` FOREIGN KEY (`electricity_meter_reading_id`) REFERENCES `electricity_meter_readings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_meters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_meters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `electricity_connection_id` bigint unsigned NOT NULL,
  `meter_number` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meter_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `make` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `multiplication_factor` decimal(18,4) NOT NULL DEFAULT '1.0000',
  `installed_at` date DEFAULT NULL,
  `removed_at` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `electricity_meters_company_id_meter_number_unique` (`company_id`,`meter_number`),
  KEY `electricity_meters_created_by_foreign` (`created_by`),
  KEY `electricity_meters_updated_by_foreign` (`updated_by`),
  KEY `electricity_meters_deleted_by_foreign` (`deleted_by`),
  KEY `elec_meter_conn_active` (`electricity_connection_id`,`is_active`),
  CONSTRAINT `electricity_meters_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `electricity_meters_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_meters_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_meters_electricity_connection_id_foreign` FOREIGN KEY (`electricity_connection_id`) REFERENCES `electricity_connections` (`id`),
  CONSTRAINT `electricity_meters_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_tariff_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_tariff_components` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `electricity_tariff_id` bigint unsigned NOT NULL,
  `component_code` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculation_basis` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `percentage` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `slab_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `tod_slot_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `formula_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `unit` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_rebate` tinyint(1) NOT NULL DEFAULT '0',
  `is_tax` tinyint(1) NOT NULL DEFAULT '0',
  `is_penalty` tinyint(1) NOT NULL DEFAULT '0',
  `is_manual_adjustment` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `elec_tariff_comp_unique` (`electricity_tariff_id`,`component_code`),
  CONSTRAINT `electricity_tariff_components_electricity_tariff_id_foreign` FOREIGN KEY (`electricity_tariff_id`) REFERENCES `electricity_tariffs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_tariffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_tariffs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `electricity_connection_id` bigint unsigned NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `tariff_category` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `import_source` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_batch_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `electricity_tariffs_company_id_foreign` (`company_id`),
  KEY `electricity_tariffs_created_by_foreign` (`created_by`),
  KEY `electricity_tariffs_updated_by_foreign` (`updated_by`),
  KEY `electricity_tariffs_deleted_by_foreign` (`deleted_by`),
  KEY `elec_tariff_conn_dates` (`electricity_connection_id`,`effective_from`,`effective_to`),
  CONSTRAINT `electricity_tariffs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `electricity_tariffs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_tariffs_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_tariffs_electricity_connection_id_foreign` FOREIGN KEY (`electricity_connection_id`) REFERENCES `electricity_connections` (`id`),
  CONSTRAINT `electricity_tariffs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `electricity_workbook_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `electricity_workbook_import_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `batch_uuid` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_file` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_sha256` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rows_parsed` int unsigned NOT NULL DEFAULT '0',
  `rows_imported` int unsigned NOT NULL DEFAULT '0',
  `rows_skipped` int unsigned NOT NULL DEFAULT '0',
  `rows_failed` int unsigned NOT NULL DEFAULT '0',
  `rows_conflict` int unsigned NOT NULL DEFAULT '0',
  `warnings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `report_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_by` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `elec_wb_batch_uuid` (`company_id`,`batch_uuid`),
  KEY `electricity_workbook_import_batches_started_by_foreign` (`started_by`),
  KEY `elec_wb_batch_hash` (`company_id`,`file_sha256`),
  CONSTRAINT `electricity_workbook_import_batches_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `electricity_workbook_import_batches_started_by_foreign` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `electricity_workbook_import_batches_chk_1` CHECK (json_valid(`warnings`)),
  CONSTRAINT `electricity_workbook_import_batches_chk_2` CHECK (json_valid(`summary`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `equipment_type_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ownership` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_vendor_id` bigint unsigned DEFAULT NULL,
  `source_purchase_order_id` bigint unsigned DEFAULT NULL,
  `source_grn_id` bigint unsigned DEFAULT NULL,
  `registration_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `make` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capacity_rating` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manufacture_year` smallint unsigned DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `warranty_start` date DEFAULT NULL,
  `warranty_end` date DEFAULT NULL,
  `purchase_value` decimal(18,2) DEFAULT NULL,
  `hourly_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `daily_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'central_store',
  `location_project_id` bigint unsigned DEFAULT NULL,
  `location_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_notes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_updated_at` timestamp NULL DEFAULT NULL,
  `location_updated_by` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `tracking_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual',
  `opening_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `available_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `equipment_company_id_code_unique` (`company_id`,`code`),
  UNIQUE KEY `equipment_company_id_registration_no_unique` (`company_id`,`registration_no`),
  UNIQUE KEY `equipment_company_id_serial_no_unique` (`company_id`,`serial_no`),
  KEY `equipment_equipment_type_id_foreign` (`equipment_type_id`),
  KEY `equipment_owner_vendor_id_foreign` (`owner_vendor_id`),
  KEY `equipment_created_by_foreign` (`created_by`),
  KEY `equipment_updated_by_foreign` (`updated_by`),
  KEY `equipment_deleted_by_foreign` (`deleted_by`),
  KEY `equipment_company_id_status_index` (`company_id`,`status`),
  KEY `equipment_location_project_id_foreign` (`location_project_id`),
  KEY `equipment_location_updated_by_foreign` (`location_updated_by`),
  KEY `equipment_source_purchase_order_id_foreign` (`source_purchase_order_id`),
  KEY `equipment_source_grn_id_foreign` (`source_grn_id`),
  CONSTRAINT `equipment_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `equipment_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_equipment_type_id_foreign` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`),
  CONSTRAINT `equipment_location_project_id_foreign` FOREIGN KEY (`location_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_location_updated_by_foreign` FOREIGN KEY (`location_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_owner_vendor_id_foreign` FOREIGN KEY (`owner_vendor_id`) REFERENCES `vendors` (`id`),
  CONSTRAINT `equipment_source_grn_id_foreign` FOREIGN KEY (`source_grn_id`) REFERENCES `grns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_source_purchase_order_id_foreign` FOREIGN KEY (`source_purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `equipment_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `issue_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `operator_labour_id` bigint unsigned DEFAULT NULL,
  `operator_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rate_basis` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate` decimal(18,4) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `returned_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `equipment_assignments_company_id_foreign` (`company_id`),
  KEY `equipment_assignments_site_id_foreign` (`site_id`),
  KEY `equipment_assignments_task_id_foreign` (`task_id`),
  KEY `equipment_assignments_operator_labour_id_foreign` (`operator_labour_id`),
  KEY `equipment_assignments_returned_by_foreign` (`returned_by`),
  KEY `equipment_assignments_created_by_foreign` (`created_by`),
  KEY `equipment_assignments_updated_by_foreign` (`updated_by`),
  KEY `equipment_assignments_equipment_id_issue_date_index` (`equipment_id`,`issue_date`),
  KEY `equipment_assignments_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `equipment_assignments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `equipment_assignments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_assignments_equipment_id_foreign` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  CONSTRAINT `equipment_assignments_operator_labour_id_foreign` FOREIGN KEY (`operator_labour_id`) REFERENCES `labours` (`id`),
  CONSTRAINT `equipment_assignments_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `equipment_assignments_returned_by_foreign` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_assignments_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`),
  CONSTRAINT `equipment_assignments_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_assignments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_fuel_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_fuel_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `equipment_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `warehouse_id` bigint unsigned DEFAULT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `material_issue_id` bigint unsigned DEFAULT NULL,
  `material_issue_item_id` bigint unsigned DEFAULT NULL,
  `operator_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meter_reading` decimal(14,2) DEFAULT NULL,
  `log_date` date NOT NULL,
  `opening_fuel` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fuel_added` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fuel_consumed` decimal(12,2) NOT NULL DEFAULT '0.00',
  `closing_fuel` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fuel_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `equipment_fuel_logs_company_id_foreign` (`company_id`),
  KEY `equipment_fuel_logs_created_by_foreign` (`created_by`),
  KEY `equipment_fuel_logs_updated_by_foreign` (`updated_by`),
  KEY `equipment_fuel_logs_equipment_id_log_date_index` (`equipment_id`,`log_date`),
  KEY `equipment_fuel_logs_project_id_log_date_index` (`project_id`,`log_date`),
  KEY `equipment_fuel_logs_material_id_foreign` (`material_id`),
  KEY `equipment_fuel_logs_unit_id_foreign` (`unit_id`),
  KEY `equipment_fuel_logs_warehouse_id_foreign` (`warehouse_id`),
  KEY `equipment_fuel_logs_site_id_foreign` (`site_id`),
  KEY `equipment_fuel_logs_material_issue_id_foreign` (`material_issue_id`),
  KEY `equipment_fuel_logs_material_issue_item_id_foreign` (`material_issue_item_id`),
  CONSTRAINT `equipment_fuel_logs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `equipment_fuel_logs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_fuel_logs_equipment_id_foreign` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  CONSTRAINT `equipment_fuel_logs_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `equipment_fuel_logs_material_issue_id_foreign` FOREIGN KEY (`material_issue_id`) REFERENCES `material_issues` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_fuel_logs_material_issue_item_id_foreign` FOREIGN KEY (`material_issue_item_id`) REFERENCES `material_issue_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_fuel_logs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `equipment_fuel_logs_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_fuel_logs_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `equipment_fuel_logs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_fuel_logs_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_location_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_location_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `equipment_id` bigint unsigned NOT NULL,
  `from_location_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_location_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_project_id` bigint unsigned DEFAULT NULL,
  `to_location_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_location_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_project_id` bigint unsigned DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changed_at` timestamp NOT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `equipment_location_history_company_id_foreign` (`company_id`),
  KEY `equipment_location_history_from_project_id_foreign` (`from_project_id`),
  KEY `equipment_location_history_to_project_id_foreign` (`to_project_id`),
  KEY `equipment_location_history_changed_by_foreign` (`changed_by`),
  KEY `equipment_location_history_equipment_id_changed_at_index` (`equipment_id`,`changed_at`),
  CONSTRAINT `equipment_location_history_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_location_history_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `equipment_location_history_equipment_id_foreign` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  CONSTRAINT `equipment_location_history_from_project_id_foreign` FOREIGN KEY (`from_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_location_history_to_project_id_foreign` FOREIGN KEY (`to_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_quantity_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_quantity_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `equipment_id` bigint unsigned NOT NULL,
  `moved_at` date NOT NULL,
  `movement_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `reference_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `equipment_quantity_movements_project_id_foreign` (`project_id`),
  KEY `equipment_quantity_movements_created_by_foreign` (`created_by`),
  KEY `equipment_quantity_movements_equipment_id_moved_at_index` (`equipment_id`,`moved_at`),
  KEY `equipment_quantity_movements_company_id_moved_at_index` (`company_id`,`moved_at`),
  CONSTRAINT `equipment_quantity_movements_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `equipment_quantity_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_quantity_movements_equipment_id_foreign` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  CONSTRAINT `equipment_quantity_movements_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_repairs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_repairs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `equipment_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `repair_date` date NOT NULL,
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `expense_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `completed_date` date DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `equipment_repairs_company_id_foreign` (`company_id`),
  KEY `equipment_repairs_vendor_id_foreign` (`vendor_id`),
  KEY `equipment_repairs_created_by_foreign` (`created_by`),
  KEY `equipment_repairs_updated_by_foreign` (`updated_by`),
  KEY `equipment_repairs_equipment_id_index` (`equipment_id`),
  KEY `equipment_repairs_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `equipment_repairs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `equipment_repairs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_repairs_equipment_id_foreign` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  CONSTRAINT `equipment_repairs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `equipment_repairs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_repairs_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `equipment_types_company_id_name_unique` (`company_id`,`name`),
  KEY `equipment_types_created_by_foreign` (`created_by`),
  KEY `equipment_types_updated_by_foreign` (`updated_by`),
  KEY `equipment_types_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `equipment_types_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `equipment_types_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_types_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_types_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_usage_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_usage_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `equipment_assignment_id` bigint unsigned NOT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `log_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `total_duration_hours` decimal(8,2) DEFAULT NULL,
  `opening_meter` decimal(12,2) DEFAULT NULL,
  `closing_meter` decimal(12,2) DEFAULT NULL,
  `working_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `idle_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `cost_amount` decimal(18,2) DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `equipment_usage_logs_equipment_assignment_id_log_date_unique` (`equipment_assignment_id`,`log_date`),
  KEY `equipment_usage_logs_company_id_foreign` (`company_id`),
  KEY `equipment_usage_logs_task_id_foreign` (`task_id`),
  KEY `equipment_usage_logs_posted_by_foreign` (`posted_by`),
  KEY `equipment_usage_logs_created_by_foreign` (`created_by`),
  KEY `equipment_usage_logs_updated_by_foreign` (`updated_by`),
  KEY `equipment_usage_logs_project_id_log_date_index` (`project_id`,`log_date`),
  CONSTRAINT `equipment_usage_logs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `equipment_usage_logs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_usage_logs_equipment_assignment_id_foreign` FOREIGN KEY (`equipment_assignment_id`) REFERENCES `equipment_assignments` (`id`),
  CONSTRAINT `equipment_usage_logs_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_usage_logs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `equipment_usage_logs_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `equipment_usage_logs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cost_head` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `expense_categories_company_id_name_unique` (`company_id`,`name`),
  KEY `expense_categories_created_by_foreign` (`created_by`),
  KEY `expense_categories_updated_by_foreign` (`updated_by`),
  KEY `expense_categories_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `expense_categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `expense_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_categories_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_categories_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `expense_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expense_category_id` bigint unsigned NOT NULL,
  `cost_head` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `payee_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expense_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `tax_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(18,2) NOT NULL,
  `payment_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `petty_cash_account_id` bigint unsigned DEFAULT NULL,
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int unsigned NOT NULL DEFAULT '0',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `payment_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reversed_by` bigint unsigned DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversal_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `expenses_company_id_expense_number_unique` (`company_id`,`expense_number`),
  KEY `expenses_expense_category_id_foreign` (`expense_category_id`),
  KEY `expenses_vendor_id_foreign` (`vendor_id`),
  KEY `expenses_task_id_foreign` (`task_id`),
  KEY `expenses_boq_item_id_foreign` (`boq_item_id`),
  KEY `expenses_approved_by_foreign` (`approved_by`),
  KEY `expenses_paid_by_foreign` (`paid_by`),
  KEY `expenses_reversed_by_foreign` (`reversed_by`),
  KEY `expenses_created_by_foreign` (`created_by`),
  KEY `expenses_updated_by_foreign` (`updated_by`),
  KEY `expenses_deleted_by_foreign` (`deleted_by`),
  KEY `expenses_project_id_expense_date_index` (`project_id`,`expense_date`),
  KEY `expenses_project_id_status_index` (`project_id`,`status`),
  KEY `expenses_petty_cash_account_id_index` (`petty_cash_account_id`),
  KEY `expenses_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `expenses_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `expenses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `expenses_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_petty_cash_account_id_foreign` FOREIGN KEY (`petty_cash_account_id`) REFERENCES `petty_cash_accounts` (`id`),
  CONSTRAINT `expenses_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `expenses_reversed_by_foreign` FOREIGN KEY (`reversed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_previews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_previews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `source_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `source_checksum` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preview_format` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preview_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preview_checksum` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_previews_company_id_source_type_source_id_unique` (`company_id`,`source_type`,`source_id`),
  CONSTRAINT `file_previews_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `financial_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_years` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `financial_years_company_id_name_unique` (`company_id`,`name`),
  CONSTRAINT `financial_years_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gate_pass_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gate_pass_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `gate_pass_id` bigint unsigned NOT NULL,
  `item_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `equipment_id` bigint unsigned DEFAULT NULL,
  `description` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `weight` decimal(18,4) DEFAULT NULL,
  `scrap_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scrap_type_id` bigint unsigned DEFAULT NULL,
  `rate` decimal(18,4) DEFAULT NULL,
  `destination` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gate_pass_items_company_id_foreign` (`company_id`),
  KEY `gate_pass_items_gate_pass_id_foreign` (`gate_pass_id`),
  KEY `gate_pass_items_material_id_foreign` (`material_id`),
  KEY `gate_pass_items_equipment_id_foreign` (`equipment_id`),
  KEY `gate_pass_items_unit_id_foreign` (`unit_id`),
  KEY `gate_pass_items_scrap_type_id_foreign` (`scrap_type_id`),
  CONSTRAINT `gate_pass_items_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `gate_pass_items_equipment_id_foreign` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  CONSTRAINT `gate_pass_items_gate_pass_id_foreign` FOREIGN KEY (`gate_pass_id`) REFERENCES `gate_passes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gate_pass_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `gate_pass_items_scrap_type_id_foreign` FOREIGN KEY (`scrap_type_id`) REFERENCES `scrap_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_pass_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gate_pass_overdue_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gate_pass_overdue_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `gate_pass_id` bigint unsigned NOT NULL,
  `alert_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gp_overdue_alert_unique` (`gate_pass_id`,`alert_date`),
  CONSTRAINT `gate_pass_overdue_alerts_gate_pass_id_foreign` FOREIGN KEY (`gate_pass_id`) REFERENCES `gate_passes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gate_pass_return_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gate_pass_return_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `gate_pass_return_id` bigint unsigned NOT NULL,
  `gate_pass_item_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `condition` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'good',
  `condition_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gate_pass_return_items_gate_pass_return_id_foreign` (`gate_pass_return_id`),
  KEY `gate_pass_return_items_gate_pass_item_id_foreign` (`gate_pass_item_id`),
  CONSTRAINT `gate_pass_return_items_gate_pass_item_id_foreign` FOREIGN KEY (`gate_pass_item_id`) REFERENCES `gate_pass_items` (`id`),
  CONSTRAINT `gate_pass_return_items_gate_pass_return_id_foreign` FOREIGN KEY (`gate_pass_return_id`) REFERENCES `gate_pass_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gate_pass_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gate_pass_returns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `gate_pass_id` bigint unsigned NOT NULL,
  `return_date` date NOT NULL,
  `returned_by` bigint unsigned NOT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'recorded',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gate_pass_returns_company_id_foreign` (`company_id`),
  KEY `gate_pass_returns_returned_by_foreign` (`returned_by`),
  KEY `gate_pass_returns_verified_by_foreign` (`verified_by`),
  KEY `gate_pass_returns_created_by_foreign` (`created_by`),
  KEY `gate_pass_returns_updated_by_foreign` (`updated_by`),
  KEY `gate_pass_returns_gate_pass_id_status_index` (`gate_pass_id`,`status`),
  CONSTRAINT `gate_pass_returns_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gate_pass_returns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_pass_returns_gate_pass_id_foreign` FOREIGN KEY (`gate_pass_id`) REFERENCES `gate_passes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gate_pass_returns_returned_by_foreign` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `gate_pass_returns_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_pass_returns_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gate_passes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gate_passes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `pass_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `returnable` tinyint(1) NOT NULL DEFAULT '0',
  `passed_at` datetime NOT NULL,
  `expected_return_date` date DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `party_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `vehicle_no` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transporter_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transporter_contact` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_contact` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purpose` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permanent_removal_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dispatch_notes` text COLLATE utf8mb4_unicode_ci,
  `closure_notes` text COLLATE utf8mb4_unicode_ci,
  `reference_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejected_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gate_passes_company_id_pass_number_unique` (`company_id`,`pass_number`),
  KEY `gate_passes_site_id_foreign` (`site_id`),
  KEY `gate_passes_vendor_id_foreign` (`vendor_id`),
  KEY `gate_passes_approved_by_foreign` (`approved_by`),
  KEY `gate_passes_verified_by_foreign` (`verified_by`),
  KEY `gate_passes_created_by_foreign` (`created_by`),
  KEY `gate_passes_updated_by_foreign` (`updated_by`),
  KEY `gate_passes_project_id_status_index` (`project_id`,`status`),
  KEY `gate_passes_department_id_foreign` (`department_id`),
  CONSTRAINT `gate_passes_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_passes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `gate_passes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_passes_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_passes_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `gate_passes_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_passes_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_passes_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gate_passes_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `grn_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grn_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `grn_id` bigint unsigned NOT NULL,
  `purchase_order_item_id` bigint unsigned NOT NULL,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `material_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `ordered_qty` decimal(18,4) NOT NULL,
  `previously_received_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `po_remaining_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `received_qty` decimal(18,4) NOT NULL,
  `rejected_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `accepted_qty` decimal(18,4) NOT NULL,
  `extra_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `extra_reason` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extra_remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_challan_qty` decimal(18,4) DEFAULT NULL,
  `rejection_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rate` decimal(18,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grn_items_grn_id_purchase_order_item_id_unique` (`grn_id`,`purchase_order_item_id`),
  KEY `grn_items_material_id_foreign` (`material_id`),
  KEY `grn_items_unit_id_foreign` (`unit_id`),
  KEY `grn_items_purchase_order_item_id_index` (`purchase_order_item_id`),
  KEY `grn_items_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `grn_items_grn_id_foreign` FOREIGN KEY (`grn_id`) REFERENCES `grns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grn_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `grn_items_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grn_items_purchase_order_item_id_foreign` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`),
  CONSTRAINT `grn_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `grns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `purchase_order_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned DEFAULT NULL,
  `grn_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_date` date NOT NULL,
  `vendor_invoice_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vendor_invoice_date` date DEFAULT NULL,
  `vendor_challan_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_no` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `extra_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grns_company_id_grn_number_unique` (`company_id`,`grn_number`),
  UNIQUE KEY `grns_uuid_unique` (`uuid`),
  KEY `grns_vendor_id_foreign` (`vendor_id`),
  KEY `grns_warehouse_id_foreign` (`warehouse_id`),
  KEY `grns_approved_by_foreign` (`approved_by`),
  KEY `grns_created_by_foreign` (`created_by`),
  KEY `grns_updated_by_foreign` (`updated_by`),
  KEY `grns_deleted_by_foreign` (`deleted_by`),
  KEY `grns_purchase_order_id_index` (`purchase_order_id`),
  KEY `grns_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `grns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grns_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `grns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grns_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grns_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `grns_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`),
  CONSTRAINT `grns_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `grns_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`),
  CONSTRAINT `grns_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_orphans_approval_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_orphans_approval_actions` (
  `id` bigint unsigned NOT NULL,
  `approval_request_id` bigint unsigned NOT NULL,
  `level` tinyint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comments` text COLLATE utf8mb4_unicode_ci,
  `acted_at` timestamp NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_orphans_dpr_labours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_orphans_dpr_labours` (
  `id` bigint unsigned NOT NULL,
  `dpr_id` bigint unsigned NOT NULL,
  `labour_trade_id` bigint unsigned NOT NULL,
  `subcontractor_id` bigint unsigned DEFAULT NULL,
  `headcount` int unsigned NOT NULL,
  `skilled` int unsigned DEFAULT NULL,
  `unskilled` int unsigned DEFAULT NULL,
  `operator_count` int unsigned DEFAULT NULL,
  `helper_count` int unsigned DEFAULT NULL,
  `hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_informational` tinyint(1) NOT NULL DEFAULT '1',
  `source` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_orphans_dpr_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_orphans_dpr_materials` (
  `id` bigint unsigned NOT NULL,
  `dpr_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `opening_qty` decimal(18,4) DEFAULT NULL,
  `received_qty` decimal(18,4) DEFAULT NULL,
  `issued_qty` decimal(18,4) DEFAULT NULL,
  `closing_qty` decimal(18,4) DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_informational` tinyint(1) NOT NULL DEFAULT '0',
  `source` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inventory',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
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
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labour_advances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labour_advances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `labour_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `advance_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `recovered_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labour_advances_company_id_foreign` (`company_id`),
  KEY `labour_advances_created_by_foreign` (`created_by`),
  KEY `labour_advances_updated_by_foreign` (`updated_by`),
  KEY `labour_advances_labour_id_index` (`labour_id`),
  KEY `labour_advances_project_id_advance_date_index` (`project_id`,`advance_date`),
  CONSTRAINT `labour_advances_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `labour_advances_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_advances_labour_id_foreign` FOREIGN KEY (`labour_id`) REFERENCES `labours` (`id`),
  CONSTRAINT `labour_advances_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `labour_advances_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labour_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labour_attendance` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sync_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `labour_id` bigint unsigned NOT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `punch_in` time DEFAULT NULL,
  `punch_out` time DEFAULT NULL,
  `working_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `ot_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `daily_wage` decimal(18,2) NOT NULL,
  `ot_rate` decimal(18,4) NOT NULL,
  `wage_amount` decimal(18,2) NOT NULL,
  `ot_amount` decimal(18,2) NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approval_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marked_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `labour_payment_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `labour_attendance_labour_id_attendance_date_unique` (`labour_id`,`attendance_date`),
  UNIQUE KEY `labour_attendance_uuid_unique` (`uuid`),
  KEY `labour_attendance_company_id_foreign` (`company_id`),
  KEY `labour_attendance_site_id_foreign` (`site_id`),
  KEY `labour_attendance_task_id_foreign` (`task_id`),
  KEY `labour_attendance_marked_by_foreign` (`marked_by`),
  KEY `labour_attendance_approved_by_foreign` (`approved_by`),
  KEY `labour_attendance_labour_payment_id_foreign` (`labour_payment_id`),
  KEY `labour_attendance_created_by_foreign` (`created_by`),
  KEY `labour_attendance_updated_by_foreign` (`updated_by`),
  KEY `labour_attendance_project_id_attendance_date_index` (`project_id`,`attendance_date`),
  KEY `labour_attendance_project_id_approval_status_index` (`project_id`,`approval_status`),
  KEY `labour_attendance_project_id_sync_uuid_index` (`project_id`,`sync_uuid`),
  CONSTRAINT `labour_attendance_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_attendance_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `labour_attendance_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_attendance_labour_id_foreign` FOREIGN KEY (`labour_id`) REFERENCES `labours` (`id`),
  CONSTRAINT `labour_attendance_labour_payment_id_foreign` FOREIGN KEY (`labour_payment_id`) REFERENCES `labour_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_attendance_marked_by_foreign` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_attendance_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `labour_attendance_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`),
  CONSTRAINT `labour_attendance_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_attendance_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labour_payment_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labour_payment_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `labour_payment_id` bigint unsigned NOT NULL,
  `labour_id` bigint unsigned NOT NULL,
  `present_days` decimal(8,1) NOT NULL DEFAULT '0.0',
  `half_days` decimal(8,1) NOT NULL DEFAULT '0.0',
  `ot_hours` decimal(10,2) NOT NULL DEFAULT '0.00',
  `gross_wage` decimal(18,2) NOT NULL,
  `ot_amount` decimal(18,2) NOT NULL,
  `advance_recovery` decimal(18,2) NOT NULL DEFAULT '0.00',
  `other_deductions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `net_amount` decimal(18,2) NOT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `labour_payment_lines_labour_payment_id_labour_id_unique` (`labour_payment_id`,`labour_id`),
  KEY `labour_payment_lines_labour_id_index` (`labour_id`),
  CONSTRAINT `labour_payment_lines_labour_id_foreign` FOREIGN KEY (`labour_id`) REFERENCES `labours` (`id`),
  CONSTRAINT `labour_payment_lines_labour_payment_id_foreign` FOREIGN KEY (`labour_payment_id`) REFERENCES `labour_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labour_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labour_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `payment_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `total_gross` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_ot` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_deductions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_net` decimal(18,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `paid_by` bigint unsigned DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `payment_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `return_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `labour_payments_company_id_payment_number_unique` (`company_id`,`payment_number`),
  KEY `labour_payments_submitted_by_foreign` (`submitted_by`),
  KEY `labour_payments_approved_by_foreign` (`approved_by`),
  KEY `labour_payments_paid_by_foreign` (`paid_by`),
  KEY `labour_payments_created_by_foreign` (`created_by`),
  KEY `labour_payments_updated_by_foreign` (`updated_by`),
  KEY `labour_payments_deleted_by_foreign` (`deleted_by`),
  KEY `labour_payments_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `labour_payments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_payments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `labour_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_payments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_payments_paid_by_foreign` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_payments_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `labour_payments_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_payments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labour_trades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labour_trades` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_daily_wage` decimal(18,2) NOT NULL DEFAULT '0.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `labour_trades_company_id_name_unique` (`company_id`,`name`),
  KEY `labour_trades_created_by_foreign` (`created_by`),
  KEY `labour_trades_updated_by_foreign` (`updated_by`),
  KEY `labour_trades_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `labour_trades_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `labour_trades_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_trades_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labour_trades_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labours` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `labour_trade_id` bigint unsigned NOT NULL,
  `subcontractor_id` bigint unsigned DEFAULT NULL,
  `daily_wage` decimal(18,2) NOT NULL,
  `ot_rate_per_hour` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `current_project_id` bigint unsigned DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `id_proof_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_proof_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `labours_company_id_code_unique` (`company_id`,`code`),
  UNIQUE KEY `labours_uuid_unique` (`uuid`),
  KEY `labours_labour_trade_id_foreign` (`labour_trade_id`),
  KEY `labours_subcontractor_id_foreign` (`subcontractor_id`),
  KEY `labours_created_by_foreign` (`created_by`),
  KEY `labours_updated_by_foreign` (`updated_by`),
  KEY `labours_deleted_by_foreign` (`deleted_by`),
  KEY `labours_current_project_id_index` (`current_project_id`),
  KEY `labours_company_id_name_index` (`company_id`,`name`),
  CONSTRAINT `labours_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `labours_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labours_current_project_id_foreign` FOREIGN KEY (`current_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labours_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labours_labour_trade_id_foreign` FOREIGN KEY (`labour_trade_id`) REFERENCES `labour_trades` (`id`),
  CONSTRAINT `labours_subcontractor_id_foreign` FOREIGN KEY (`subcontractor_id`) REFERENCES `subcontractors` (`id`),
  CONSTRAINT `labours_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `lead_id` bigint unsigned NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_at` datetime NOT NULL,
  `summary` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_follow_up` date DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `lead_activities_company_id_foreign` (`company_id`),
  KEY `lead_activities_created_by_foreign` (`created_by`),
  KEY `lead_activities_lead_id_activity_at_index` (`lead_id`,`activity_at`),
  CONSTRAINT `lead_activities_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `lead_activities_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_activities_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `lead_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estimated_value` decimal(18,2) NOT NULL DEFAULT '0.00',
  `expected_close_date` date DEFAULT NULL,
  `assigned_to` bigint unsigned DEFAULT NULL,
  `client_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lost_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leads_company_id_lead_number_unique` (`company_id`,`lead_number`),
  KEY `leads_client_id_foreign` (`client_id`),
  KEY `leads_created_by_foreign` (`created_by`),
  KEY `leads_updated_by_foreign` (`updated_by`),
  KEY `leads_deleted_by_foreign` (`deleted_by`),
  KEY `leads_company_id_status_index` (`company_id`,`status`),
  KEY `leads_assigned_to_index` (`assigned_to`),
  CONSTRAINT `leads_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  CONSTRAINT `leads_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `leads_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `low_stock_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `low_stock_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `reorder_level` decimal(18,4) NOT NULL,
  `alerted_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `low_stock_alerts_warehouse_id_material_id_unique` (`warehouse_id`,`material_id`),
  KEY `low_stock_alerts_company_id_foreign` (`company_id`),
  KEY `low_stock_alerts_material_id_foreign` (`material_id`),
  CONSTRAINT `low_stock_alerts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `low_stock_alerts_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `low_stock_alerts_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_categories_company_id_code_unique` (`company_id`,`code`),
  KEY `material_categories_parent_id_foreign` (`parent_id`),
  KEY `material_categories_created_by_foreign` (`created_by`),
  KEY `material_categories_updated_by_foreign` (`updated_by`),
  KEY `material_categories_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `material_categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `material_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_categories_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `material_categories` (`id`),
  CONSTRAINT `material_categories_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_category_wbs_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_category_wbs_maps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `material_category_id` bigint unsigned NOT NULL,
  `project_cost_head_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mat_cat_wbs_unique` (`company_id`,`material_category_id`),
  KEY `material_category_wbs_maps_material_category_id_foreign` (`material_category_id`),
  KEY `material_category_wbs_maps_project_cost_head_id_foreign` (`project_cost_head_id`),
  CONSTRAINT `material_category_wbs_maps_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_category_wbs_maps_material_category_id_foreign` FOREIGN KEY (`material_category_id`) REFERENCES `material_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_category_wbs_maps_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_issue_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_issue_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `material_issue_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_cost` decimal(18,4) DEFAULT NULL,
  `amount` decimal(18,2) DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `material_issue_items_material_issue_id_foreign` (`material_issue_id`),
  KEY `material_issue_items_unit_id_foreign` (`unit_id`),
  KEY `material_issue_items_boq_item_id_foreign` (`boq_item_id`),
  KEY `material_issue_items_task_id_foreign` (`task_id`),
  KEY `material_issue_items_material_id_index` (`material_id`),
  KEY `material_issue_items_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `material_issue_items_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`),
  CONSTRAINT `material_issue_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `material_issue_items_material_issue_id_foreign` FOREIGN KEY (`material_issue_id`) REFERENCES `material_issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_issue_items_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_issue_items_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`),
  CONSTRAINT `material_issue_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_issues` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `issue_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `issue_date` date NOT NULL,
  `issued_to_user_id` bigint unsigned DEFAULT NULL,
  `subcontractor_id` bigint unsigned DEFAULT NULL,
  `issued_to_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purpose` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_issues_company_id_issue_number_unique` (`company_id`,`issue_number`),
  KEY `material_issues_issued_to_user_id_foreign` (`issued_to_user_id`),
  KEY `material_issues_subcontractor_id_foreign` (`subcontractor_id`),
  KEY `material_issues_approved_by_foreign` (`approved_by`),
  KEY `material_issues_cancelled_by_foreign` (`cancelled_by`),
  KEY `material_issues_created_by_foreign` (`created_by`),
  KEY `material_issues_updated_by_foreign` (`updated_by`),
  KEY `material_issues_deleted_by_foreign` (`deleted_by`),
  KEY `material_issues_project_id_status_index` (`project_id`,`status`),
  KEY `material_issues_warehouse_id_index` (`warehouse_id`),
  CONSTRAINT `material_issues_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_issues_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_issues_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `material_issues_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_issues_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_issues_issued_to_user_id_foreign` FOREIGN KEY (`issued_to_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `material_issues_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `material_issues_subcontractor_id_foreign` FOREIGN KEY (`subcontractor_id`) REFERENCES `subcontractors` (`id`),
  CONSTRAINT `material_issues_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_issues_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_recipe_template_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_recipe_template_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `recipe_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `consumption_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `consumption_unit` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `wastage_percent` decimal(7,4) DEFAULT NULL,
  `sequence` int unsigned NOT NULL DEFAULT '0',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recipe_lines_unique_material_unit` (`recipe_id`,`material_id`,`consumption_unit`),
  KEY `material_recipe_template_lines_material_id_foreign` (`material_id`),
  KEY `material_recipe_template_lines_recipe_id_sequence_index` (`recipe_id`,`sequence`),
  CONSTRAINT `material_recipe_template_lines_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `material_recipe_template_lines_recipe_id_foreign` FOREIGN KEY (`recipe_id`) REFERENCES `material_recipe_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_recipe_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_recipe_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `version` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `supersedes_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_recipe_templates_company_id_code_version_unique` (`company_id`,`code`,`version`),
  KEY `material_recipe_templates_project_id_foreign` (`project_id`),
  KEY `material_recipe_templates_supersedes_id_foreign` (`supersedes_id`),
  KEY `material_recipe_templates_created_by_foreign` (`created_by`),
  KEY `material_recipe_templates_updated_by_foreign` (`updated_by`),
  KEY `material_recipe_templates_company_id_status_index` (`company_id`,`status`),
  KEY `material_recipe_templates_company_id_project_id_index` (`company_id`,`project_id`),
  CONSTRAINT `material_recipe_templates_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `material_recipe_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_recipe_templates_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_recipe_templates_supersedes_id_foreign` FOREIGN KEY (`supersedes_id`) REFERENCES `material_recipe_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_recipe_templates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_request_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_request_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `material_request_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `ordered_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `received_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `material_request_items_material_request_id_foreign` (`material_request_id`),
  KEY `material_request_items_boq_item_id_foreign` (`boq_item_id`),
  KEY `material_request_items_task_id_foreign` (`task_id`),
  KEY `material_request_items_unit_id_foreign` (`unit_id`),
  KEY `material_request_items_material_id_index` (`material_id`),
  KEY `material_request_items_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `material_request_items_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_request_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `material_request_items_material_request_id_foreign` FOREIGN KEY (`material_request_id`) REFERENCES `material_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_request_items_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_request_items_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_request_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `request_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_date` date NOT NULL,
  `required_date` date DEFAULT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `source_type` varchar(20) NOT NULL DEFAULT 'general',
  `boq_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `drawing_revision_id` bigint unsigned DEFAULT NULL,
  `source_reason` varchar(500) DEFAULT NULL,
  `source_label_snapshot` varchar(255) DEFAULT NULL,
  `procurement_category` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'material',
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `equipment_meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancelled_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_requests_company_id_request_number_unique` (`company_id`,`request_number`),
  KEY `material_requests_site_id_foreign` (`site_id`),
  KEY `material_requests_requested_by_foreign` (`requested_by`),
  KEY `material_requests_approved_by_foreign` (`approved_by`),
  KEY `material_requests_created_by_foreign` (`created_by`),
  KEY `material_requests_updated_by_foreign` (`updated_by`),
  KEY `material_requests_deleted_by_foreign` (`deleted_by`),
  KEY `material_requests_project_id_status_index` (`project_id`,`status`),
  KEY `material_requests_boq_id_foreign` (`boq_id`),
  KEY `material_requests_boq_item_id_foreign` (`boq_item_id`),
  KEY `material_requests_drawing_revision_id_foreign` (`drawing_revision_id`),
  CONSTRAINT `material_requests_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_requests_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_requests_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_requests_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `material_requests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_requests_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_requests_drawing_revision_id_foreign` FOREIGN KEY (`drawing_revision_id`) REFERENCES `drawing_revisions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_requests_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `material_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `material_requests_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`),
  CONSTRAINT `material_requests_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_return_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_return_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `material_return_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `material_issue_item_id` bigint unsigned DEFAULT NULL,
  `grn_item_id` bigint unsigned DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_cost` decimal(18,4) DEFAULT NULL,
  `value` decimal(18,2) DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `material_return_items_material_return_id_foreign` (`material_return_id`),
  KEY `material_return_items_material_id_foreign` (`material_id`),
  KEY `material_return_items_unit_id_foreign` (`unit_id`),
  KEY `material_return_items_material_issue_item_id_index` (`material_issue_item_id`),
  KEY `material_return_items_grn_item_id_index` (`grn_item_id`),
  CONSTRAINT `material_return_items_grn_item_id_foreign` FOREIGN KEY (`grn_item_id`) REFERENCES `grn_items` (`id`),
  CONSTRAINT `material_return_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `material_return_items_material_issue_item_id_foreign` FOREIGN KEY (`material_issue_item_id`) REFERENCES `material_issue_items` (`id`),
  CONSTRAINT `material_return_items_material_return_id_foreign` FOREIGN KEY (`material_return_id`) REFERENCES `material_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_return_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `material_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_returns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `return_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `return_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `return_date` date NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned DEFAULT NULL,
  `grn_id` bigint unsigned DEFAULT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_returns_company_id_return_number_unique` (`company_id`,`return_number`),
  KEY `material_returns_warehouse_id_foreign` (`warehouse_id`),
  KEY `material_returns_vendor_id_foreign` (`vendor_id`),
  KEY `material_returns_grn_id_foreign` (`grn_id`),
  KEY `material_returns_approved_by_foreign` (`approved_by`),
  KEY `material_returns_cancelled_by_foreign` (`cancelled_by`),
  KEY `material_returns_created_by_foreign` (`created_by`),
  KEY `material_returns_updated_by_foreign` (`updated_by`),
  KEY `material_returns_deleted_by_foreign` (`deleted_by`),
  KEY `material_returns_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `material_returns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_returns_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_returns_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `material_returns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_returns_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_returns_grn_id_foreign` FOREIGN KEY (`grn_id`) REFERENCES `grns` (`id`),
  CONSTRAINT `material_returns_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `material_returns_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_returns_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`),
  CONSTRAINT `material_returns_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `materials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `material_category_id` bigint unsigned DEFAULT NULL,
  `item_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'material',
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `hsn_sac` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `reorder_level` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `standard_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `materials_company_id_code_unique` (`company_id`,`code`),
  KEY `materials_material_category_id_foreign` (`material_category_id`),
  KEY `materials_unit_id_foreign` (`unit_id`),
  KEY `materials_tax_rate_id_foreign` (`tax_rate_id`),
  KEY `materials_created_by_foreign` (`created_by`),
  KEY `materials_updated_by_foreign` (`updated_by`),
  KEY `materials_deleted_by_foreign` (`deleted_by`),
  KEY `materials_company_id_material_category_id_index` (`company_id`,`material_category_id`),
  KEY `materials_company_id_name_index` (`company_id`,`name`),
  CONSTRAINT `materials_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `materials_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `materials_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `materials_material_category_id_foreign` FOREIGN KEY (`material_category_id`) REFERENCES `material_categories` (`id`),
  CONSTRAINT `materials_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`),
  CONSTRAINT `materials_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `materials_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `disk` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `message_attachments_company_id_foreign` (`company_id`),
  KEY `message_attachments_uploaded_by_foreign` (`uploaded_by`),
  KEY `message_attachments_message_id_index` (`message_id`),
  CONSTRAINT `message_attachments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `message_attachments_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `conversation_id` bigint unsigned NOT NULL,
  `sender_id` bigint unsigned NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `reply_to_message_id` bigint unsigned DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messages_company_id_foreign` (`company_id`),
  KEY `messages_reply_to_message_id_foreign` (`reply_to_message_id`),
  KEY `messages_conversation_id_id_index` (`conversation_id`,`id`),
  KEY `messages_sender_id_index` (`sender_id`),
  CONSTRAINT `messages_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_reply_to_message_id_foreign` FOREIGN KEY (`reply_to_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messages_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `team_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`team_id`,`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_permissions_permission_id_foreign` (`permission_id`),
  KEY `model_has_permissions_team_foreign_key_index` (`team_id`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `team_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`team_id`,`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_roles_role_id_foreign` (`role_id`),
  KEY `model_has_roles_team_foreign_key_index` (`team_id`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ncrs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ncrs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `quality_inspection_id` bigint unsigned DEFAULT NULL,
  `ncr_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `issue` varchar(2000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsible_user_id` bigint unsigned DEFAULT NULL,
  `subcontractor_id` bigint unsigned DEFAULT NULL,
  `target_date` date DEFAULT NULL,
  `root_cause` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `corrective_action` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resolved_by` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verification_remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `closed_by` bigint unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ncrs_company_id_ncr_number_unique` (`company_id`,`ncr_number`),
  KEY `ncrs_subcontractor_id_foreign` (`subcontractor_id`),
  KEY `ncrs_resolved_by_foreign` (`resolved_by`),
  KEY `ncrs_verified_by_foreign` (`verified_by`),
  KEY `ncrs_closed_by_foreign` (`closed_by`),
  KEY `ncrs_created_by_foreign` (`created_by`),
  KEY `ncrs_updated_by_foreign` (`updated_by`),
  KEY `ncrs_deleted_by_foreign` (`deleted_by`),
  KEY `ncrs_project_id_status_index` (`project_id`,`status`),
  KEY `ncrs_quality_inspection_id_index` (`quality_inspection_id`),
  KEY `ncrs_responsible_user_id_index` (`responsible_user_id`),
  CONSTRAINT `ncrs_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ncrs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `ncrs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ncrs_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ncrs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `ncrs_quality_inspection_id_foreign` FOREIGN KEY (`quality_inspection_id`) REFERENCES `quality_inspections` (`id`),
  CONSTRAINT `ncrs_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ncrs_responsible_user_id_foreign` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ncrs_subcontractor_id_foreign` FOREIGN KEY (`subcontractor_id`) REFERENCES `subcontractors` (`id`),
  CONSTRAINT `ncrs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ncrs_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `notification_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_preferences_user_id_notification_type_unique` (`user_id`,`notification_type`),
  CONSTRAINT `notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `company_id` bigint unsigned DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`),
  KEY `notifications_company_id_foreign` (`company_id`),
  KEY `notifications_notifiable_type_notifiable_id_read_at_index` (`notifiable_type`,`notifiable_id`,`read_at`),
  KEY `notifications_inbox_index` (`notifiable_type`,`notifiable_id`,`company_id`,`created_at`),
  CONSTRAINT `notifications_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint unsigned NOT NULL,
  `payable_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payable_id` bigint unsigned NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_allocations_unique` (`payment_id`,`payable_type`,`payable_id`),
  KEY `payment_allocations_payable_type_payable_id_index` (`payable_type`,`payable_id`),
  CONSTRAINT `payment_allocations_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `payment_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` bigint unsigned NOT NULL,
  `payment_date` date NOT NULL,
  `mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL,
  `tds_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_company_id_payment_number_unique` (`company_id`,`payment_number`),
  KEY `payments_approved_by_foreign` (`approved_by`),
  KEY `payments_cancelled_by_foreign` (`cancelled_by`),
  KEY `payments_created_by_foreign` (`created_by`),
  KEY `payments_updated_by_foreign` (`updated_by`),
  KEY `payments_deleted_by_foreign` (`deleted_by`),
  KEY `payments_project_id_payment_date_index` (`project_id`,`payment_date`),
  KEY `payments_party_type_party_id_index` (`party_type`,`party_id`),
  KEY `payments_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `payments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `payments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `petty_cash_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `holder_user_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `limit_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `petty_cash_accounts_project_id_name_unique` (`project_id`,`name`),
  KEY `petty_cash_accounts_company_id_foreign` (`company_id`),
  KEY `petty_cash_accounts_created_by_foreign` (`created_by`),
  KEY `petty_cash_accounts_updated_by_foreign` (`updated_by`),
  KEY `petty_cash_accounts_holder_user_id_index` (`holder_user_id`),
  CONSTRAINT `petty_cash_accounts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `petty_cash_accounts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_accounts_holder_user_id_foreign` FOREIGN KEY (`holder_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `petty_cash_accounts_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `petty_cash_accounts_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `petty_cash_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `petty_cash_account_id` bigint unsigned NOT NULL,
  `txn_date` date NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `expense_id` bigint unsigned DEFAULT NULL,
  `reverses_id` bigint unsigned DEFAULT NULL,
  `posting_ref` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `petty_cash_transactions_posting_ref_unique` (`posting_ref`),
  UNIQUE KEY `petty_cash_transactions_reverses_id_unique` (`reverses_id`),
  KEY `petty_cash_transactions_company_id_foreign` (`company_id`),
  KEY `petty_cash_transactions_created_by_foreign` (`created_by`),
  KEY `petty_cash_transactions_petty_cash_account_id_txn_date_index` (`petty_cash_account_id`,`txn_date`),
  KEY `petty_cash_transactions_expense_id_index` (`expense_id`),
  CONSTRAINT `petty_cash_transactions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `petty_cash_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_transactions_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`),
  CONSTRAINT `petty_cash_transactions_petty_cash_account_id_foreign` FOREIGN KEY (`petty_cash_account_id`) REFERENCES `petty_cash_accounts` (`id`),
  CONSTRAINT `petty_cash_transactions_reverses_id_foreign` FOREIGN KEY (`reverses_id`) REFERENCES `petty_cash_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `progress_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `progress_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entry_date` date NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `boq_quantity` decimal(18,4) DEFAULT NULL,
  `source_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `posting_ref` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reverses_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `progress_entries_posting_ref_unique` (`posting_ref`),
  UNIQUE KEY `progress_entries_reverses_id_unique` (`reverses_id`),
  KEY `progress_entries_company_id_foreign` (`company_id`),
  KEY `progress_entries_boq_item_id_foreign` (`boq_item_id`),
  KEY `progress_entries_created_by_foreign` (`created_by`),
  KEY `progress_entries_task_id_entry_date_index` (`task_id`,`entry_date`),
  KEY `progress_entries_project_id_boq_line_uid_index` (`project_id`,`boq_line_uid`),
  KEY `progress_entries_source_type_source_id_index` (`source_type`,`source_id`),
  CONSTRAINT `progress_entries_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`),
  CONSTRAINT `progress_entries_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `progress_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `progress_entries_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `progress_entries_reverses_id_foreign` FOREIGN KEY (`reverses_id`) REFERENCES `progress_entries` (`id`),
  CONSTRAINT `progress_entries_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_budget_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_budget_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_budget_id` bigint unsigned NOT NULL,
  `cost_head` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `material_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(18,4) DEFAULT NULL,
  `rate` decimal(18,4) DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sources` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pbl_budget_source_key_unique` (`project_budget_id`,`source_type`,`source_key`),
  KEY `project_budget_lines_boq_item_id_foreign` (`boq_item_id`),
  KEY `project_budget_lines_project_budget_id_cost_head_index` (`project_budget_id`,`cost_head`),
  KEY `project_budget_lines_material_id_foreign` (`material_id`),
  KEY `project_budget_lines_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `project_budget_lines_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_budget_lines_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_budget_lines_project_budget_id_foreign` FOREIGN KEY (`project_budget_id`) REFERENCES `project_budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_budget_lines_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_budgets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `budget_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main_project',
  `version` smallint unsigned NOT NULL,
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `boq_id` bigint unsigned DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `materials_synced_at` timestamp NULL DEFAULT NULL,
  `materials_sync_pending` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_budgets_type_version_unique` (`project_id`,`budget_type`,`version`),
  KEY `project_budgets_boq_id_foreign` (`boq_id`),
  KEY `project_budgets_approved_by_foreign` (`approved_by`),
  KEY `project_budgets_created_by_foreign` (`created_by`),
  KEY `project_budgets_updated_by_foreign` (`updated_by`),
  KEY `project_budgets_deleted_by_foreign` (`deleted_by`),
  KEY `project_budgets_company_id_status_index` (`company_id`,`status`),
  KEY `project_budgets_type_status_index` (`project_id`,`budget_type`,`status`),
  CONSTRAINT `project_budgets_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_budgets_boq_id_foreign` FOREIGN KEY (`boq_id`) REFERENCES `boqs` (`id`),
  CONSTRAINT `project_budgets_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `project_budgets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_budgets_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_budgets_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `project_budgets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_cash_flow_plan_months`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_cash_flow_plan_months` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_cash_flow_plan_id` bigint unsigned NOT NULL,
  `year_month` char(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `planned_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cash_flow_plan_month_unique` (`project_cash_flow_plan_id`,`year_month`),
  CONSTRAINT `project_cash_flow_plan_months_project_cash_flow_plan_id_foreign` FOREIGN KEY (`project_cash_flow_plan_id`) REFERENCES `project_cash_flow_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_cash_flow_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_cash_flow_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Cash Flow Plan',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `total_planned` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_cash_flow_plans_created_by_foreign` (`created_by`),
  KEY `project_cash_flow_plans_updated_by_foreign` (`updated_by`),
  KEY `project_cash_flow_plans_company_id_project_id_index` (`company_id`,`project_id`),
  KEY `project_cash_flow_plans_project_id_is_active_index` (`project_id`,`is_active`),
  CONSTRAINT `project_cash_flow_plans_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `project_cash_flow_plans_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_cash_flow_plans_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_cash_flow_plans_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_cost_heads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_cost_heads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_cost_heads_company_id_code_unique` (`company_id`,`code`),
  KEY `project_cost_heads_created_by_foreign` (`created_by`),
  KEY `project_cost_heads_updated_by_foreign` (`updated_by`),
  KEY `project_cost_heads_company_id_is_active_sort_order_index` (`company_id`,`is_active`,`sort_order`),
  KEY `project_cost_heads_parent_id_foreign` (`parent_id`),
  KEY `project_cost_heads_company_id_parent_id_index` (`company_id`,`parent_id`),
  CONSTRAINT `project_cost_heads_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `project_cost_heads_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_cost_heads_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_cost_heads_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_cost_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_cost_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `cost_head` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `entry_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `source_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `posting_ref` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `reverses_id` bigint unsigned DEFAULT NULL,
  `is_reversal` tinyint(1) NOT NULL DEFAULT '0',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_cost_ledger_posting_unique` (`source_type`,`source_id`,`cost_head`,`is_reversal`,`posting_ref`),
  UNIQUE KEY `project_cost_ledger_reverses_id_unique` (`reverses_id`),
  KEY `project_cost_ledger_company_id_foreign` (`company_id`),
  KEY `project_cost_ledger_boq_item_id_foreign` (`boq_item_id`),
  KEY `project_cost_ledger_created_by_foreign` (`created_by`),
  KEY `project_cost_ledger_project_id_cost_head_entry_date_index` (`project_id`,`cost_head`,`entry_date`),
  KEY `project_cost_ledger_project_id_boq_line_uid_index` (`project_id`,`boq_line_uid`),
  KEY `project_cost_ledger_task_id_index` (`task_id`),
  CONSTRAINT `project_cost_ledger_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`),
  CONSTRAINT `project_cost_ledger_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `project_cost_ledger_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_cost_ledger_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `project_cost_ledger_reverses_id_foreign` FOREIGN KEY (`reverses_id`) REFERENCES `project_cost_ledger` (`id`),
  CONSTRAINT `project_cost_ledger_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_milestones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_milestones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `planned_start` date DEFAULT NULL,
  `planned_end` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `completed_at` date DEFAULT NULL,
  `billing_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `responsible_user_id` bigint unsigned DEFAULT NULL,
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_milestones_company_id_foreign` (`company_id`),
  KEY `project_milestones_created_by_foreign` (`created_by`),
  KEY `project_milestones_updated_by_foreign` (`updated_by`),
  KEY `project_milestones_project_id_due_date_index` (`project_id`,`due_date`),
  KEY `project_milestones_responsible_user_id_foreign` (`responsible_user_id`),
  CONSTRAINT `project_milestones_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `project_milestones_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_milestones_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `project_milestones_responsible_user_id_foreign` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_milestones_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_settings_project_id_key_unique` (`project_id`,`key`),
  CONSTRAINT `project_settings_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `milestone_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `wbs_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `assigned_to` bigint unsigned DEFAULT NULL,
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `planned_start` date DEFAULT NULL,
  `planned_finish` date DEFAULT NULL,
  `duration_days` int unsigned DEFAULT NULL,
  `actual_start` date DEFAULT NULL,
  `actual_finish` date DEFAULT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `planned_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `daily_target_qty` decimal(18,4) DEFAULT NULL,
  `completed_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `hold_quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `progress_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `budget_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `actual_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_tasks_project_id_wbs_code_unique` (`project_id`,`wbs_code`),
  KEY `project_tasks_company_id_foreign` (`company_id`),
  KEY `project_tasks_parent_id_foreign` (`parent_id`),
  KEY `project_tasks_milestone_id_foreign` (`milestone_id`),
  KEY `project_tasks_boq_item_id_foreign` (`boq_item_id`),
  KEY `project_tasks_unit_id_foreign` (`unit_id`),
  KEY `project_tasks_created_by_foreign` (`created_by`),
  KEY `project_tasks_updated_by_foreign` (`updated_by`),
  KEY `project_tasks_deleted_by_foreign` (`deleted_by`),
  KEY `project_tasks_project_id_parent_id_index` (`project_id`,`parent_id`),
  KEY `project_tasks_assigned_to_status_index` (`assigned_to`,`status`),
  KEY `project_tasks_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `project_tasks_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_tasks_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_tasks_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `project_tasks_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_tasks_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_tasks_milestone_id_foreign` FOREIGN KEY (`milestone_id`) REFERENCES `project_milestones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_tasks_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `project_tasks` (`id`),
  CONSTRAINT `project_tasks_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_tasks_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `project_tasks_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `project_tasks_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `project_role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_role_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_users_project_id_user_id_unique` (`project_id`,`user_id`),
  KEY `project_users_created_by_foreign` (`created_by`),
  KEY `project_users_updated_by_foreign` (`updated_by`),
  KEY `project_users_user_id_is_active_index` (`user_id`,`is_active`),
  KEY `project_users_company_role_id_foreign` (`company_role_id`),
  CONSTRAINT `project_users_company_role_id_foreign` FOREIGN KEY (`company_role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_users_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_users_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_users_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_users_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `client_id` bigint unsigned DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `project_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `project_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `project_manager_id` bigint unsigned DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `expected_end_date` date DEFAULT NULL,
  `actual_end_date` date DEFAULT NULL,
  `contract_value` decimal(18,2) NOT NULL DEFAULT '0.00',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planning',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `projects_company_id_project_number_unique` (`company_id`,`project_number`),
  UNIQUE KEY `projects_company_id_code_unique` (`company_id`,`code`),
  KEY `projects_client_id_foreign` (`client_id`),
  KEY `projects_project_manager_id_foreign` (`project_manager_id`),
  KEY `projects_created_by_foreign` (`created_by`),
  KEY `projects_updated_by_foreign` (`updated_by`),
  KEY `projects_deleted_by_foreign` (`deleted_by`),
  KEY `projects_company_id_status_index` (`company_id`,`status`),
  KEY `projects_department_id_foreign` (`department_id`),
  CONSTRAINT `projects_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  CONSTRAINT `projects_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `projects_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_project_manager_id_foreign` FOREIGN KEY (`project_manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `purchase_order_id` bigint unsigned NOT NULL,
  `material_request_item_id` bigint unsigned DEFAULT NULL,
  `vendor_quotation_item_id` bigint unsigned DEFAULT NULL,
  `material_id` bigint unsigned NOT NULL,
  `item_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hsn_sac` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `make` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `rate` decimal(18,4) NOT NULL,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `base_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `cgst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `cgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sgst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `sgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `igst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `igst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `received_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_order_items_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `purchase_order_items_vendor_quotation_item_id_foreign` (`vendor_quotation_item_id`),
  KEY `purchase_order_items_material_id_foreign` (`material_id`),
  KEY `purchase_order_items_unit_id_foreign` (`unit_id`),
  KEY `purchase_order_items_tax_rate_id_foreign` (`tax_rate_id`),
  KEY `purchase_order_items_material_request_item_id_index` (`material_request_item_id`),
  KEY `purchase_order_items_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `purchase_order_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `purchase_order_items_material_request_item_id_foreign` FOREIGN KEY (`material_request_item_id`) REFERENCES `material_request_items` (`id`),
  CONSTRAINT `purchase_order_items_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_items_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_items_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`),
  CONSTRAINT `purchase_order_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `purchase_order_items_vendor_quotation_item_id_foreign` FOREIGN KEY (`vendor_quotation_item_id`) REFERENCES `vendor_quotation_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_order_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint unsigned NOT NULL,
  `revision_no` smallint unsigned NOT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_order_revisions_purchase_order_id_revision_no_unique` (`purchase_order_id`,`revision_no`),
  KEY `purchase_order_revisions_created_by_foreign` (`created_by`),
  CONSTRAINT `purchase_order_revisions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_revisions_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `po_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision_no` smallint unsigned NOT NULL DEFAULT '0',
  `rfq_id` bigint unsigned DEFAULT NULL,
  `vendor_quotation_id` bigint unsigned DEFAULT NULL,
  `direct_justification` text COLLATE utf8mb4_unicode_ci,
  `po_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `reference_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_date` date DEFAULT NULL,
  `billing_address` text COLLATE utf8mb4_unicode_ci,
  `shipping_address` text COLLATE utf8mb4_unicode_ci,
  `consignee_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consignee_email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consignee_gstin` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vendor_state_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `place_of_supply_state` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_terms` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `terms` text COLLATE utf8mb4_unicode_ci,
  `document_terms_template_id` bigint unsigned DEFAULT NULL,
  `document_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `other_references` text COLLATE utf8mb4_unicode_ci,
  `dispatched_through` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destination` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_terms` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `cgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `igst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `freight_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `other_charges` decimal(18,2) NOT NULL DEFAULT '0.00',
  `round_off` decimal(18,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(18,2) NOT NULL DEFAULT '0.00',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancelled_reason` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_orders_company_id_po_number_unique` (`company_id`,`po_number`),
  KEY `purchase_orders_vendor_quotation_id_foreign` (`vendor_quotation_id`),
  KEY `purchase_orders_approved_by_foreign` (`approved_by`),
  KEY `purchase_orders_cancelled_by_foreign` (`cancelled_by`),
  KEY `purchase_orders_created_by_foreign` (`created_by`),
  KEY `purchase_orders_updated_by_foreign` (`updated_by`),
  KEY `purchase_orders_deleted_by_foreign` (`deleted_by`),
  KEY `purchase_orders_project_id_status_index` (`project_id`,`status`),
  KEY `purchase_orders_vendor_id_po_date_index` (`vendor_id`,`po_date`),
  KEY `purchase_orders_rfq_id_index` (`rfq_id`),
  KEY `purchase_orders_document_terms_template_id_foreign` (`document_terms_template_id`),
  CONSTRAINT `purchase_orders_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `purchase_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_document_terms_template_id_foreign` FOREIGN KEY (`document_terms_template_id`) REFERENCES `document_terms_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `purchase_orders_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`),
  CONSTRAINT `purchase_orders_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`),
  CONSTRAINT `purchase_orders_vendor_quotation_id_foreign` FOREIGN KEY (`vendor_quotation_id`) REFERENCES `vendor_quotations` (`id`),
  CONSTRAINT `purchase_orders_chk_1` CHECK (json_valid(`document_snapshot`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quality_checklist_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quality_checklist_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quality_checklist_id` bigint unsigned NOT NULL,
  `checkpoint` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acceptance_criteria` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quality_checklist_items_quality_checklist_id_sort_order_index` (`quality_checklist_id`,`sort_order`),
  CONSTRAINT `quality_checklist_items_quality_checklist_id_foreign` FOREIGN KEY (`quality_checklist_id`) REFERENCES `quality_checklists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quality_checklists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quality_checklists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `discipline` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quality_checklists_company_id_name_unique` (`company_id`,`name`),
  KEY `quality_checklists_created_by_foreign` (`created_by`),
  KEY `quality_checklists_updated_by_foreign` (`updated_by`),
  CONSTRAINT `quality_checklists_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `quality_checklists_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_checklists_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quality_inspection_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quality_inspection_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quality_inspection_id` bigint unsigned NOT NULL,
  `quality_checklist_item_id` bigint unsigned DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `checkpoint` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acceptance_criteria` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quality_inspection_items_quality_checklist_item_id_foreign` (`quality_checklist_item_id`),
  KEY `quality_inspection_items_quality_inspection_id_sort_order_index` (`quality_inspection_id`,`sort_order`),
  CONSTRAINT `quality_inspection_items_quality_checklist_item_id_foreign` FOREIGN KEY (`quality_checklist_item_id`) REFERENCES `quality_checklist_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspection_items_quality_inspection_id_foreign` FOREIGN KEY (`quality_inspection_id`) REFERENCES `quality_inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quality_inspections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quality_inspections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `inspection_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quality_checklist_id` bigint unsigned NOT NULL,
  `request_notes` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_by` bigint unsigned DEFAULT NULL,
  `inspection_date` date DEFAULT NULL,
  `engineer_id` bigint unsigned DEFAULT NULL,
  `result` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `completed_by` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quality_inspections_company_id_inspection_number_unique` (`company_id`,`inspection_number`),
  KEY `quality_inspections_site_id_foreign` (`site_id`),
  KEY `quality_inspections_task_id_foreign` (`task_id`),
  KEY `quality_inspections_boq_item_id_foreign` (`boq_item_id`),
  KEY `quality_inspections_requested_by_foreign` (`requested_by`),
  KEY `quality_inspections_engineer_id_foreign` (`engineer_id`),
  KEY `quality_inspections_completed_by_foreign` (`completed_by`),
  KEY `quality_inspections_created_by_foreign` (`created_by`),
  KEY `quality_inspections_updated_by_foreign` (`updated_by`),
  KEY `quality_inspections_deleted_by_foreign` (`deleted_by`),
  KEY `quality_inspections_project_id_status_index` (`project_id`,`status`),
  KEY `quality_inspections_project_id_inspection_date_index` (`project_id`,`inspection_date`),
  KEY `quality_inspections_quality_checklist_id_index` (`quality_checklist_id`),
  CONSTRAINT `quality_inspections_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `quality_inspections_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_engineer_id_foreign` FOREIGN KEY (`engineer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `quality_inspections_quality_checklist_id_foreign` FOREIGN KEY (`quality_checklist_id`) REFERENCES `quality_checklists` (`id`),
  CONSTRAINT `quality_inspections_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quotation_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotation_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quotation_id` bigint unsigned NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hsn_sac` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `rate` decimal(18,4) NOT NULL,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `base_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `cgst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `cgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sgst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `sgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `igst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `igst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quotation_items_unit_id_foreign` (`unit_id`),
  KEY `quotation_items_tax_rate_id_foreign` (`tax_rate_id`),
  KEY `quotation_items_quotation_id_index` (`quotation_id`),
  CONSTRAINT `quotation_items_quotation_id_foreign` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quotation_items_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`),
  CONSTRAINT `quotation_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `lead_id` bigint unsigned DEFAULT NULL,
  `client_id` bigint unsigned DEFAULT NULL,
  `quotation_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` smallint unsigned NOT NULL DEFAULT '0',
  `parent_quotation_id` bigint unsigned DEFAULT NULL,
  `quotation_date` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `place_of_supply_state` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `cgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `igst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `terms` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `decided_by` bigint unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `converted_project_id` bigint unsigned DEFAULT NULL,
  `converted_by` bigint unsigned DEFAULT NULL,
  `converted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quotations_number_revision_unique` (`company_id`,`quotation_number`,`revision`),
  UNIQUE KEY `quotations_converted_project_id_unique` (`converted_project_id`),
  KEY `quotations_parent_quotation_id_foreign` (`parent_quotation_id`),
  KEY `quotations_decided_by_foreign` (`decided_by`),
  KEY `quotations_converted_by_foreign` (`converted_by`),
  KEY `quotations_created_by_foreign` (`created_by`),
  KEY `quotations_updated_by_foreign` (`updated_by`),
  KEY `quotations_deleted_by_foreign` (`deleted_by`),
  KEY `quotations_company_id_status_index` (`company_id`,`status`),
  KEY `quotations_lead_id_index` (`lead_id`),
  KEY `quotations_client_id_index` (`client_id`),
  CONSTRAINT `quotations_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  CONSTRAINT `quotations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `quotations_converted_by_foreign` FOREIGN KEY (`converted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotations_converted_project_id_foreign` FOREIGN KEY (`converted_project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `quotations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotations_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotations_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`),
  CONSTRAINT `quotations_parent_quotation_id_foreign` FOREIGN KEY (`parent_quotation_id`) REFERENCES `quotations` (`id`),
  CONSTRAINT `quotations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_analyses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_analyses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `unit_id` bigint unsigned NOT NULL,
  `output_quantity` decimal(18,4) NOT NULL DEFAULT '1.0000',
  `overhead_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `profit_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `material_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `labour_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `equipment_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `subcontract_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `other_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `overhead_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `profit_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `unit_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rate_analyses_company_id_code_unique` (`company_id`,`code`),
  KEY `rate_analyses_project_id_foreign` (`project_id`),
  KEY `rate_analyses_unit_id_foreign` (`unit_id`),
  KEY `rate_analyses_approved_by_foreign` (`approved_by`),
  KEY `rate_analyses_created_by_foreign` (`created_by`),
  KEY `rate_analyses_updated_by_foreign` (`updated_by`),
  KEY `rate_analyses_deleted_by_foreign` (`deleted_by`),
  KEY `rate_analyses_company_id_project_id_status_index` (`company_id`,`project_id`,`status`),
  CONSTRAINT `rate_analyses_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rate_analyses_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `rate_analyses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rate_analyses_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rate_analyses_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `rate_analyses_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `rate_analyses_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_analysis_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_analysis_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rate_analysis_id` bigint unsigned NOT NULL,
  `resource_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `labour_trade_id` bigint unsigned DEFAULT NULL,
  `equipment_type_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `wastage_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rate_analysis_items_material_id_foreign` (`material_id`),
  KEY `rate_analysis_items_labour_trade_id_foreign` (`labour_trade_id`),
  KEY `rate_analysis_items_equipment_type_id_foreign` (`equipment_type_id`),
  KEY `rate_analysis_items_unit_id_foreign` (`unit_id`),
  KEY `rate_analysis_items_rate_analysis_id_sort_order_index` (`rate_analysis_id`,`sort_order`),
  CONSTRAINT `rate_analysis_items_equipment_type_id_foreign` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`),
  CONSTRAINT `rate_analysis_items_labour_trade_id_foreign` FOREIGN KEY (`labour_trade_id`) REFERENCES `labour_trades` (`id`),
  CONSTRAINT `rate_analysis_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `rate_analysis_items_rate_analysis_id_foreign` FOREIGN KEY (`rate_analysis_id`) REFERENCES `rate_analyses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rate_analysis_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `report_exports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_exports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `report_key` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `format` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `filters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned DEFAULT NULL,
  `row_count` int unsigned DEFAULT NULL,
  `error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `report_exports_user_id_foreign` (`user_id`),
  KEY `report_exports_project_id_foreign` (`project_id`),
  KEY `report_exports_company_id_user_id_created_at_index` (`company_id`,`user_id`,`created_at`),
  CONSTRAINT `report_exports_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `report_exports_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `report_exports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `retention_releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `retention_releases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `release_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `releasable_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `releasable_id` bigint unsigned NOT NULL,
  `release_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `retention_releases_company_id_release_number_unique` (`company_id`,`release_number`),
  KEY `retention_releases_approved_by_foreign` (`approved_by`),
  KEY `retention_releases_created_by_foreign` (`created_by`),
  KEY `retention_releases_updated_by_foreign` (`updated_by`),
  KEY `retention_releases_deleted_by_foreign` (`deleted_by`),
  KEY `retention_releases_releasable_type_releasable_id_index` (`releasable_type`,`releasable_id`),
  KEY `retention_releases_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `retention_releases_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `retention_releases_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `retention_releases_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `retention_releases_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `retention_releases_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `retention_releases_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rfq_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfq_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rfq_id` bigint unsigned NOT NULL,
  `material_request_item_id` bigint unsigned DEFAULT NULL,
  `material_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `required_date` date DEFAULT NULL,
  `specification` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rfq_items_rfq_id_foreign` (`rfq_id`),
  KEY `rfq_items_material_id_foreign` (`material_id`),
  KEY `rfq_items_unit_id_foreign` (`unit_id`),
  KEY `rfq_items_material_request_item_id_index` (`material_request_item_id`),
  CONSTRAINT `rfq_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `rfq_items_material_request_item_id_foreign` FOREIGN KEY (`material_request_item_id`) REFERENCES `material_request_items` (`id`),
  CONSTRAINT `rfq_items_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rfq_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rfq_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfq_vendors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rfq_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invited',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rfq_vendors_rfq_id_vendor_id_unique` (`rfq_id`,`vendor_id`),
  KEY `rfq_vendors_vendor_id_foreign` (`vendor_id`),
  CONSTRAINT `rfq_vendors_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rfq_vendors_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rfqs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfqs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `rfq_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rfq_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `required_date` date DEFAULT NULL,
  `terms` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `sent_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `cancelled_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rfqs_company_id_rfq_number_unique` (`company_id`,`rfq_number`),
  KEY `rfqs_created_by_foreign` (`created_by`),
  KEY `rfqs_updated_by_foreign` (`updated_by`),
  KEY `rfqs_deleted_by_foreign` (`deleted_by`),
  KEY `rfqs_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `rfqs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `rfqs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rfqs_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rfqs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `rfqs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_team_id_name_guard_name_unique` (`team_id`,`name`,`guard_name`),
  KEY `roles_team_foreign_key_index` (`team_id`),
  CONSTRAINT `roles_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_notification_dedupe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_notification_dedupe` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `alert_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scheduled_notify_dedupe_unique` (`event_key`,`document_type`,`document_id`,`alert_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_adjustment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scrap_adjustment_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `scrap_adjustment_id` bigint unsigned NOT NULL,
  `scrap_type_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scrap_adjustment_items_company_id_foreign` (`company_id`),
  KEY `scrap_adjustment_items_scrap_adjustment_id_foreign` (`scrap_adjustment_id`),
  KEY `scrap_adjustment_items_scrap_type_id_foreign` (`scrap_type_id`),
  KEY `scrap_adjustment_items_unit_id_foreign` (`unit_id`),
  CONSTRAINT `scrap_adjustment_items_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `scrap_adjustment_items_scrap_adjustment_id_foreign` FOREIGN KEY (`scrap_adjustment_id`) REFERENCES `scrap_adjustments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scrap_adjustment_items_scrap_type_id_foreign` FOREIGN KEY (`scrap_type_id`) REFERENCES `scrap_types` (`id`),
  CONSTRAINT `scrap_adjustment_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scrap_adjustments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `adjustment_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `adjustment_date` date NOT NULL,
  `direction` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scrap_adjustments_company_id_adjustment_number_unique` (`company_id`,`adjustment_number`),
  KEY `scrap_adjustments_approved_by_foreign` (`approved_by`),
  KEY `scrap_adjustments_created_by_foreign` (`created_by`),
  KEY `scrap_adjustments_updated_by_foreign` (`updated_by`),
  KEY `scrap_adjustments_deleted_by_foreign` (`deleted_by`),
  KEY `scrap_adjustments_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `scrap_adjustments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_adjustments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `scrap_adjustments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_adjustments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_adjustments_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `scrap_adjustments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scrap_balances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `scrap_type_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `qty_generated` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `qty_dispatched` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `qty_adjusted_net` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `last_movement_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scrap_balances_unique` (`company_id`,`project_id`,`scrap_type_id`,`unit_id`),
  KEY `scrap_balances_project_id_foreign` (`project_id`),
  KEY `scrap_balances_scrap_type_id_foreign` (`scrap_type_id`),
  KEY `scrap_balances_unit_id_foreign` (`unit_id`),
  CONSTRAINT `scrap_balances_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `scrap_balances_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `scrap_balances_scrap_type_id_foreign` FOREIGN KEY (`scrap_type_id`) REFERENCES `scrap_types` (`id`),
  CONSTRAINT `scrap_balances_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scrap_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scrap_categories_company_id_code_unique` (`company_id`,`code`),
  KEY `scrap_categories_created_by_foreign` (`created_by`),
  KEY `scrap_categories_updated_by_foreign` (`updated_by`),
  CONSTRAINT `scrap_categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `scrap_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_categories_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_generation_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scrap_generation_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `scrap_generation_id` bigint unsigned NOT NULL,
  `scrap_type_id` bigint unsigned NOT NULL,
  `description` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `weight` decimal(18,4) DEFAULT NULL,
  `condition` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estimated_rate` decimal(18,4) DEFAULT NULL,
  `estimated_value` decimal(18,2) DEFAULT NULL,
  `location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `scrap_generation_items_company_id_foreign` (`company_id`),
  KEY `scrap_generation_items_scrap_generation_id_foreign` (`scrap_generation_id`),
  KEY `scrap_generation_items_scrap_type_id_foreign` (`scrap_type_id`),
  KEY `scrap_generation_items_unit_id_foreign` (`unit_id`),
  CONSTRAINT `scrap_generation_items_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `scrap_generation_items_scrap_generation_id_foreign` FOREIGN KEY (`scrap_generation_id`) REFERENCES `scrap_generations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scrap_generation_items_scrap_type_id_foreign` FOREIGN KEY (`scrap_type_id`) REFERENCES `scrap_types` (`id`),
  CONSTRAINT `scrap_generation_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_generations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scrap_generations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `generation_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `generation_date` date NOT NULL,
  `location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_reference_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_reference_id` bigint unsigned DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `submitted_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scrap_generations_company_id_generation_number_unique` (`company_id`,`generation_number`),
  KEY `scrap_generations_department_id_foreign` (`department_id`),
  KEY `scrap_generations_submitted_by_foreign` (`submitted_by`),
  KEY `scrap_generations_approved_by_foreign` (`approved_by`),
  KEY `scrap_generations_cancelled_by_foreign` (`cancelled_by`),
  KEY `scrap_generations_created_by_foreign` (`created_by`),
  KEY `scrap_generations_updated_by_foreign` (`updated_by`),
  KEY `scrap_generations_deleted_by_foreign` (`deleted_by`),
  KEY `scrap_generations_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `scrap_generations_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_generations_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_generations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `scrap_generations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_generations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_generations_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_generations_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `scrap_generations_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_generations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_ledger_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scrap_ledger_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `scrap_type_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity_in` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `quantity_out` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `reference_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_id` bigint unsigned NOT NULL,
  `reference_number` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scrap_ledger_idempotency` (`reference_type`,`reference_id`,`transaction_type`),
  KEY `scrap_ledger_entries_project_id_foreign` (`project_id`),
  KEY `scrap_ledger_entries_scrap_type_id_foreign` (`scrap_type_id`),
  KEY `scrap_ledger_entries_unit_id_foreign` (`unit_id`),
  KEY `scrap_ledger_entries_created_by_foreign` (`created_by`),
  KEY `scrap_ledger_entries_company_id_scrap_type_id_project_id_index` (`company_id`,`scrap_type_id`,`project_id`),
  CONSTRAINT `scrap_ledger_entries_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `scrap_ledger_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_ledger_entries_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `scrap_ledger_entries_scrap_type_id_foreign` FOREIGN KEY (`scrap_type_id`) REFERENCES `scrap_types` (`id`),
  CONSTRAINT `scrap_ledger_entries_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scrap_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scrap_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `scrap_category_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scrap_types_company_id_scrap_category_id_code_unique` (`company_id`,`scrap_category_id`,`code`),
  KEY `scrap_types_scrap_category_id_foreign` (`scrap_category_id`),
  KEY `scrap_types_unit_id_foreign` (`unit_id`),
  KEY `scrap_types_created_by_foreign` (`created_by`),
  KEY `scrap_types_updated_by_foreign` (`updated_by`),
  CONSTRAINT `scrap_types_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `scrap_types_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scrap_types_scrap_category_id_foreign` FOREIGN KEY (`scrap_category_id`) REFERENCES `scrap_categories` (`id`),
  CONSTRAINT `scrap_types_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `scrap_types_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_diaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_diaries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `diary_date` date NOT NULL,
  `weather` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `temperature` decimal(5,2) DEFAULT NULL,
  `work_location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_performed` text COLLATE utf8mb4_unicode_ci,
  `issues` text COLLATE utf8mb4_unicode_ci,
  `safety_incidents` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_diaries_uuid_unique` (`uuid`),
  KEY `site_diaries_company_id_foreign` (`company_id`),
  KEY `site_diaries_site_id_foreign` (`site_id`),
  KEY `site_diaries_submitted_by_foreign` (`submitted_by`),
  KEY `site_diaries_reviewed_by_foreign` (`reviewed_by`),
  KEY `site_diaries_approved_by_foreign` (`approved_by`),
  KEY `site_diaries_rejected_by_foreign` (`rejected_by`),
  KEY `site_diaries_updated_by_foreign` (`updated_by`),
  KEY `site_diaries_deleted_by_foreign` (`deleted_by`),
  KEY `site_diaries_project_id_diary_date_index` (`project_id`,`diary_date`),
  KEY `site_diaries_created_by_diary_date_index` (`created_by`,`diary_date`),
  KEY `site_diaries_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `site_diaries_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diaries_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `site_diaries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diaries_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diaries_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `site_diaries_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diaries_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diaries_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`),
  CONSTRAINT `site_diaries_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diaries_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_diary_equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_diary_equipment` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site_diary_id` bigint unsigned NOT NULL,
  `equipment_id` bigint unsigned DEFAULT NULL,
  `equipment_type_id` bigint unsigned DEFAULT NULL,
  `description` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `working_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `idle_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_diary_equipment_site_diary_id_foreign` (`site_diary_id`),
  KEY `site_diary_equipment_equipment_type_id_foreign` (`equipment_type_id`),
  KEY `site_diary_equipment_equipment_id_index` (`equipment_id`),
  CONSTRAINT `site_diary_equipment_equipment_type_id_foreign` FOREIGN KEY (`equipment_type_id`) REFERENCES `equipment_types` (`id`),
  CONSTRAINT `site_diary_equipment_site_diary_id_foreign` FOREIGN KEY (`site_diary_id`) REFERENCES `site_diaries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_diary_labours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_diary_labours` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site_diary_id` bigint unsigned NOT NULL,
  `labour_trade_id` bigint unsigned NOT NULL,
  `subcontractor_id` bigint unsigned DEFAULT NULL,
  `headcount` int unsigned NOT NULL,
  `hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_diary_labours_site_diary_id_foreign` (`site_diary_id`),
  KEY `site_diary_labours_labour_trade_id_foreign` (`labour_trade_id`),
  KEY `site_diary_labours_subcontractor_id_foreign` (`subcontractor_id`),
  CONSTRAINT `site_diary_labours_labour_trade_id_foreign` FOREIGN KEY (`labour_trade_id`) REFERENCES `labour_trades` (`id`),
  CONSTRAINT `site_diary_labours_site_diary_id_foreign` FOREIGN KEY (`site_diary_id`) REFERENCES `site_diaries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_diary_labours_subcontractor_id_foreign` FOREIGN KEY (`subcontractor_id`) REFERENCES `subcontractors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_diary_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_diary_materials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site_diary_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_diary_materials_site_diary_id_foreign` (`site_diary_id`),
  KEY `site_diary_materials_material_id_foreign` (`material_id`),
  KEY `site_diary_materials_unit_id_foreign` (`unit_id`),
  CONSTRAINT `site_diary_materials_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `site_diary_materials_site_diary_id_foreign` FOREIGN KEY (`site_diary_id`) REFERENCES `site_diaries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_diary_materials_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_diary_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_diary_photos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_diary_id` bigint unsigned NOT NULL,
  `disk` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `thumbnail_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `taken_at` timestamp NULL DEFAULT NULL,
  `size_bytes` int unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_diary_photos_uuid_unique` (`uuid`),
  KEY `site_diary_photos_site_diary_id_foreign` (`site_diary_id`),
  KEY `site_diary_photos_created_by_foreign` (`created_by`),
  CONSTRAINT `site_diary_photos_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diary_photos_site_diary_id_foreign` FOREIGN KEY (`site_diary_id`) REFERENCES `site_diaries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_diary_work_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_diary_work_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site_diary_id` bigint unsigned NOT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `subcontractor_id` bigint unsigned DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_diary_work_items_site_diary_id_foreign` (`site_diary_id`),
  KEY `site_diary_work_items_boq_item_id_foreign` (`boq_item_id`),
  KEY `site_diary_work_items_subcontractor_id_foreign` (`subcontractor_id`),
  KEY `site_diary_work_items_unit_id_foreign` (`unit_id`),
  KEY `site_diary_work_items_task_id_index` (`task_id`),
  CONSTRAINT `site_diary_work_items_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diary_work_items_site_diary_id_foreign` FOREIGN KEY (`site_diary_id`) REFERENCES `site_diaries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_diary_work_items_subcontractor_id_foreign` FOREIGN KEY (`subcontractor_id`) REFERENCES `subcontractors` (`id`),
  CONSTRAINT `site_diary_work_items_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_diary_work_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `geofence_radius_m` int unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sites_company_id_foreign` (`company_id`),
  KEY `sites_created_by_foreign` (`created_by`),
  KEY `sites_updated_by_foreign` (`updated_by`),
  KEY `sites_deleted_by_foreign` (`deleted_by`),
  KEY `sites_project_id_is_active_index` (`project_id`,`is_active`),
  CONSTRAINT `sites_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `sites_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sites_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sites_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `sites_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `steel_unit_weight_profile_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `steel_unit_weight_profile_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profile_id` bigint unsigned NOT NULL,
  `diameter_mm` smallint unsigned NOT NULL,
  `weight_kg_per_m` decimal(18,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `steel_unit_weight_profile_lines_profile_id_diameter_mm_unique` (`profile_id`,`diameter_mm`),
  CONSTRAINT `steel_unit_weight_profile_lines_profile_id_foreign` FOREIGN KEY (`profile_id`) REFERENCES `steel_unit_weight_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `steel_unit_weight_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `steel_unit_weight_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'configured_table',
  `version` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `steel_unit_weight_profiles_company_id_name_version_unique` (`company_id`,`name`,`version`),
  KEY `steel_unit_weight_profiles_project_id_foreign` (`project_id`),
  KEY `steel_unit_weight_profiles_created_by_foreign` (`created_by`),
  KEY `steel_unit_weight_profiles_updated_by_foreign` (`updated_by`),
  KEY `steel_unit_weight_profiles_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `steel_unit_weight_profiles_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `steel_unit_weight_profiles_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `steel_unit_weight_profiles_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `steel_unit_weight_profiles_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_adjustment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_adjustment_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stock_adjustment_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `system_qty` decimal(18,4) NOT NULL,
  `physical_qty` decimal(18,4) NOT NULL,
  `difference` decimal(18,4) NOT NULL,
  `unit_cost` decimal(18,4) DEFAULT NULL,
  `value` decimal(18,2) DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_adjustment_items_stock_adjustment_id_material_id_unique` (`stock_adjustment_id`,`material_id`),
  KEY `stock_adjustment_items_material_id_foreign` (`material_id`),
  KEY `stock_adjustment_items_unit_id_foreign` (`unit_id`),
  CONSTRAINT `stock_adjustment_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `stock_adjustment_items_stock_adjustment_id_foreign` FOREIGN KEY (`stock_adjustment_id`) REFERENCES `stock_adjustments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_adjustment_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_adjustments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `adjustment_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `adjustment_date` date NOT NULL,
  `reason` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `submitted_by` bigint unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_adjustments_company_id_adjustment_number_unique` (`company_id`,`adjustment_number`),
  KEY `stock_adjustments_warehouse_id_foreign` (`warehouse_id`),
  KEY `stock_adjustments_submitted_by_foreign` (`submitted_by`),
  KEY `stock_adjustments_approved_by_foreign` (`approved_by`),
  KEY `stock_adjustments_cancelled_by_foreign` (`cancelled_by`),
  KEY `stock_adjustments_created_by_foreign` (`created_by`),
  KEY `stock_adjustments_updated_by_foreign` (`updated_by`),
  KEY `stock_adjustments_deleted_by_foreign` (`deleted_by`),
  KEY `stock_adjustments_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `stock_adjustments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `stock_adjustments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `stock_adjustments_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_balances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `avg_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `value` decimal(18,2) NOT NULL DEFAULT '0.00',
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_balances_warehouse_id_material_id_unique` (`warehouse_id`,`material_id`),
  KEY `stock_balances_material_id_foreign` (`material_id`),
  KEY `stock_balances_company_id_material_id_index` (`company_id`,`material_id`),
  CONSTRAINT `stock_balances_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `stock_balances_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `stock_balances_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `txn_date` date NOT NULL,
  `txn_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty_in` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `qty_out` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_cost` decimal(18,4) NOT NULL,
  `value` decimal(18,2) NOT NULL,
  `source_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `reverses_id` bigint unsigned DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_transactions_source_type_source_id_txn_type_unique` (`source_type`,`source_id`,`txn_type`),
  UNIQUE KEY `stock_transactions_reverses_id_unique` (`reverses_id`),
  KEY `stock_transactions_material_id_foreign` (`material_id`),
  KEY `stock_transactions_created_by_foreign` (`created_by`),
  KEY `stock_transactions_warehouse_id_material_id_txn_date_index` (`warehouse_id`,`material_id`,`txn_date`),
  KEY `stock_transactions_project_id_material_id_index` (`project_id`,`material_id`),
  KEY `stock_transactions_company_id_txn_date_index` (`company_id`,`txn_date`),
  CONSTRAINT `stock_transactions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `stock_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transactions_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `stock_transactions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `stock_transactions_reverses_id_foreign` FOREIGN KEY (`reverses_id`) REFERENCES `stock_transactions` (`id`),
  CONSTRAINT `stock_transactions_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_transfer_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfer_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stock_transfer_id` bigint unsigned NOT NULL,
  `material_id` bigint unsigned NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `unit_cost` decimal(18,4) DEFAULT NULL,
  `value` decimal(18,2) DEFAULT NULL,
  `received_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `received_value` decimal(18,2) NOT NULL DEFAULT '0.00',
  `short_closed_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_transfer_items_stock_transfer_id_material_id_unique` (`stock_transfer_id`,`material_id`),
  KEY `stock_transfer_items_material_id_foreign` (`material_id`),
  KEY `stock_transfer_items_unit_id_foreign` (`unit_id`),
  CONSTRAINT `stock_transfer_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `stock_transfer_items_stock_transfer_id_foreign` FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_transfer_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_transfer_receipt_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfer_receipt_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stock_transfer_receipt_id` bigint unsigned NOT NULL,
  `stock_transfer_item_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `value` decimal(18,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `str_receipt_items_unique` (`stock_transfer_receipt_id`,`stock_transfer_item_id`),
  KEY `stock_transfer_receipt_items_stock_transfer_item_id_index` (`stock_transfer_item_id`),
  CONSTRAINT `stock_transfer_receipt_items_stock_transfer_item_id_foreign` FOREIGN KEY (`stock_transfer_item_id`) REFERENCES `stock_transfer_items` (`id`),
  CONSTRAINT `stock_transfer_receipt_items_stock_transfer_receipt_id_foreign` FOREIGN KEY (`stock_transfer_receipt_id`) REFERENCES `stock_transfer_receipts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_transfer_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfer_receipts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stock_transfer_id` bigint unsigned NOT NULL,
  `receipt_date` date NOT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_transfer_receipts_stock_transfer_id_idempotency_key_unique` (`stock_transfer_id`,`idempotency_key`),
  KEY `stock_transfer_receipts_created_by_foreign` (`created_by`),
  CONSTRAINT `stock_transfer_receipts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transfer_receipts_stock_transfer_id_foreign` FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `transfer_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transfer_date` date NOT NULL,
  `from_warehouse_id` bigint unsigned NOT NULL,
  `to_warehouse_id` bigint unsigned NOT NULL,
  `vehicle_no` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `dispatched_by` bigint unsigned DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `close_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_transfers_company_id_transfer_number_unique` (`company_id`,`transfer_number`),
  KEY `stock_transfers_from_warehouse_id_foreign` (`from_warehouse_id`),
  KEY `stock_transfers_to_warehouse_id_foreign` (`to_warehouse_id`),
  KEY `stock_transfers_dispatched_by_foreign` (`dispatched_by`),
  KEY `stock_transfers_closed_by_foreign` (`closed_by`),
  KEY `stock_transfers_created_by_foreign` (`created_by`),
  KEY `stock_transfers_updated_by_foreign` (`updated_by`),
  KEY `stock_transfers_deleted_by_foreign` (`deleted_by`),
  KEY `stock_transfers_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `stock_transfers_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transfers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `stock_transfers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transfers_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transfers_dispatched_by_foreign` FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_transfers_from_warehouse_id_foreign` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `stock_transfers_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `stock_transfers_to_warehouse_id_foreign` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `stock_transfers_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subcontractor_bill_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subcontractor_bill_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `subcontractor_bill_id` bigint unsigned NOT NULL,
  `work_order_item_id` bigint unsigned NOT NULL,
  `wo_qty` decimal(18,4) NOT NULL,
  `previous_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `claimed_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `certified_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cumulative_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `rate` decimal(18,4) NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sc_bill_items_line_unique` (`subcontractor_bill_id`,`work_order_item_id`),
  KEY `subcontractor_bill_items_work_order_item_id_index` (`work_order_item_id`),
  KEY `subcontractor_bill_items_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `subcontractor_bill_items_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractor_bill_items_subcontractor_bill_id_foreign` FOREIGN KEY (`subcontractor_bill_id`) REFERENCES `subcontractor_bills` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subcontractor_bill_items_work_order_item_id_foreign` FOREIGN KEY (`work_order_item_id`) REFERENCES `work_order_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subcontractor_bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subcontractor_bills` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `work_order_id` bigint unsigned NOT NULL,
  `subcontractor_id` bigint unsigned NOT NULL,
  `bill_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bill_date` date NOT NULL,
  `subcontractor_invoice_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mb_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mb_date` date DEFAULT NULL,
  `mb_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mb_notes` text COLLATE utf8mb4_unicode_ci,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `tax_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `retention_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `tds_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `gross_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `retention_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `advance_recovery` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tds_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `other_deductions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `net_payable` decimal(18,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `certified_by` bigint unsigned DEFAULT NULL,
  `certified_at` timestamp NULL DEFAULT NULL,
  `processed_by` bigint unsigned DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `processing_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `processing_notes` text COLLATE utf8mb4_unicode_ci,
  `revision` int unsigned NOT NULL DEFAULT '0',
  `reopened_by` bigint unsigned DEFAULT NULL,
  `reopened_at` timestamp NULL DEFAULT NULL,
  `reopen_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subcontractor_bills_company_id_bill_number_unique` (`company_id`,`bill_number`),
  KEY `subcontractor_bills_subcontractor_id_foreign` (`subcontractor_id`),
  KEY `subcontractor_bills_certified_by_foreign` (`certified_by`),
  KEY `subcontractor_bills_reopened_by_foreign` (`reopened_by`),
  KEY `subcontractor_bills_created_by_foreign` (`created_by`),
  KEY `subcontractor_bills_updated_by_foreign` (`updated_by`),
  KEY `subcontractor_bills_deleted_by_foreign` (`deleted_by`),
  KEY `subcontractor_bills_work_order_id_status_index` (`work_order_id`,`status`),
  KEY `subcontractor_bills_project_id_status_index` (`project_id`,`status`),
  KEY `subcontractor_bills_processed_by_foreign` (`processed_by`),
  CONSTRAINT `subcontractor_bills_certified_by_foreign` FOREIGN KEY (`certified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractor_bills_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `subcontractor_bills_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractor_bills_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractor_bills_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractor_bills_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `subcontractor_bills_reopened_by_foreign` FOREIGN KEY (`reopened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractor_bills_subcontractor_id_foreign` FOREIGN KEY (`subcontractor_id`) REFERENCES `subcontractors` (`id`),
  CONSTRAINT `subcontractor_bills_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractor_bills_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subcontractors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subcontractors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trade` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gstin` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_terms` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_no` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_ifsc` varchar(11) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subcontractors_company_id_code_unique` (`company_id`,`code`),
  KEY `subcontractors_created_by_foreign` (`created_by`),
  KEY `subcontractors_updated_by_foreign` (`updated_by`),
  KEY `subcontractors_deleted_by_foreign` (`deleted_by`),
  KEY `subcontractors_company_id_name_index` (`company_id`,`name`),
  KEY `subcontractors_company_id_gstin_index` (`company_id`,`gstin`),
  CONSTRAINT `subcontractors_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `subcontractors_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractors_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subcontractors_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tally_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tally_connections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `transport` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `protocol` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'http',
  `format` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'xml',
  `host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port` smallint unsigned DEFAULT NULL,
  `tally_company_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timeout_seconds` smallint unsigned NOT NULL DEFAULT '15',
  `auto_sync` tinyint(1) NOT NULL DEFAULT '0',
  `sync_approved_transactions` tinyint(1) NOT NULL DEFAULT '1',
  `dry_run` tinyint(1) NOT NULL DEFAULT '0',
  `cost_centres_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `last_connected_at` timestamp NULL DEFAULT NULL,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `last_status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_status_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tally_connections_company_id_unique` (`company_id`),
  CONSTRAINT `tally_connections_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tally_cost_centre_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tally_cost_centre_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `tally_cost_centre_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tally_guid` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mapped',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tally_cost_centre_mappings_company_id_project_id_unique` (`company_id`,`project_id`),
  KEY `tally_cost_centre_mappings_project_id_foreign` (`project_id`),
  CONSTRAINT `tally_cost_centre_mappings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `tally_cost_centre_mappings_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tally_ledger_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tally_ledger_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `mapping_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `source_key` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `map_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tally_ledger_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tally_parent_group` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auto_create_allowed` tinyint(1) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tally_ledger_mappings_company_id_map_key_unique` (`company_id`,`map_key`),
  KEY `tally_ledger_mappings_company_id_mapping_type_index` (`company_id`,`mapping_type`),
  CONSTRAINT `tally_ledger_mappings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tally_master_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tally_master_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `source_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `tally_ledger_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tally_guid` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tally_master_mappings_company_id_source_type_source_id_unique` (`company_id`,`source_type`,`source_id`),
  CONSTRAINT `tally_master_mappings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tally_sync_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tally_sync_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `action` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `voucher_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `erp_reference` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `document_date` date DEFAULT NULL,
  `amount` decimal(18,2) DEFAULT NULL,
  `tally_guid` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tally_master_id` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tally_alter_id` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL DEFAULT '0',
  `request_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `error_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tally_sync_source_unique` (`company_id`,`source_type`,`source_id`,`action`),
  KEY `tally_sync_records_created_by_foreign` (`created_by`),
  KEY `tally_sync_records_company_id_status_index` (`company_id`,`status`),
  KEY `tally_sync_records_company_id_source_type_index` (`company_id`,`source_type`),
  KEY `tally_sync_records_project_id_document_date_index` (`project_id`,`document_date`),
  CONSTRAINT `tally_sync_records_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `tally_sync_records_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tally_sync_records_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_dependencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `predecessor_id` bigint unsigned NOT NULL,
  `successor_id` bigint unsigned NOT NULL,
  `type` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FS',
  `lag_days` smallint NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_dependencies_predecessor_id_successor_id_unique` (`predecessor_id`,`successor_id`),
  KEY `task_dependencies_successor_id_index` (`successor_id`),
  CONSTRAINT `task_dependencies_predecessor_id_foreign` FOREIGN KEY (`predecessor_id`) REFERENCES `project_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_dependencies_successor_id_foreign` FOREIGN KEY (`successor_id`) REFERENCES `project_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_overdue_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_overdue_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `task_id` bigint unsigned NOT NULL,
  `planned_finish` date NOT NULL,
  `alerted_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_overdue_alerts_task_id_planned_finish_unique` (`task_id`,`planned_finish`),
  KEY `task_overdue_alerts_company_id_foreign` (`company_id`),
  CONSTRAINT `task_overdue_alerts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `task_overdue_alerts_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tax_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rate` decimal(7,4) NOT NULL,
  `cgst_rate` decimal(7,4) NOT NULL,
  `sgst_rate` decimal(7,4) NOT NULL,
  `igst_rate` decimal(7,4) NOT NULL,
  `cess_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tax_rates_company_id_name_unique` (`company_id`,`name`),
  KEY `tax_rates_created_by_foreign` (`created_by`),
  KEY `tax_rates_updated_by_foreign` (`updated_by`),
  KEY `tax_rates_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `tax_rates_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `tax_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tax_rates_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tax_rates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `unit_conversions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `unit_conversions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `from_unit` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_unit` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `factor` decimal(18,4) NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `unit_conversions_created_by_foreign` (`created_by`),
  KEY `unit_conversions_updated_by_foreign` (`updated_by`),
  KEY `unit_conversions_company_id_from_unit_to_unit_index` (`company_id`,`from_unit`,`to_unit`),
  KEY `unit_conversions_material_id_is_active_index` (`material_id`,`is_active`),
  KEY `unit_conversions_project_id_is_active_index` (`project_id`,`is_active`),
  CONSTRAINT `unit_conversions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `unit_conversions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `unit_conversions_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `unit_conversions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `unit_conversions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `units` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decimal_places` tinyint unsigned NOT NULL DEFAULT '2',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `units_company_id_symbol_unique` (`company_id`,`symbol`),
  KEY `units_created_by_foreign` (`created_by`),
  KEY `units_updated_by_foreign` (`updated_by`),
  KEY `units_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `units_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `units_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `units_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `units_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `upload_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `upload_sessions` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `module` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `declared_mime` varchar(127) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detected_mime` varchar(127) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_size` bigint unsigned NOT NULL,
  `chunk_bytes` int unsigned NOT NULL,
  `total_chunks` int unsigned NOT NULL,
  `uploaded_chunks` int unsigned NOT NULL DEFAULT '0',
  `checksum` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chunk_checksums` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `temp_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `final_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scan_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_configured',
  `expires_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `upload_sessions_user_id_foreign` (`user_id`),
  KEY `upload_sessions_company_id_user_id_status_index` (`company_id`,`user_id`,`status`),
  CONSTRAINT `upload_sessions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `upload_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_tutorial_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_tutorial_progress` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `tutorial_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tutorial_version` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_step` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_steps` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `started_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_tutorial_unique` (`user_id`,`tutorial_key`,`tutorial_version`),
  KEY `user_tutorial_progress_user_id_status_index` (`user_id`,`status`),
  CONSTRAINT `user_tutorial_progress_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_company_id` bigint unsigned DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_current_company_id_foreign` (`current_company_id`),
  KEY `users_created_by_foreign` (`created_by`),
  KEY `users_updated_by_foreign` (`updated_by`),
  CONSTRAINT `users_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_current_company_id_foreign` FOREIGN KEY (`current_company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `utility_sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `utility_sites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `utility_sites_company_id_code_unique` (`company_id`,`code`),
  KEY `utility_sites_created_by_foreign` (`created_by`),
  KEY `utility_sites_updated_by_foreign` (`updated_by`),
  KEY `utility_sites_deleted_by_foreign` (`deleted_by`),
  KEY `utility_sites_company_id_is_active_index` (`company_id`,`is_active`),
  CONSTRAINT `utility_sites_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `utility_sites_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `utility_sites_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `utility_sites_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vendor_bill_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_bill_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `vendor_bill_id` bigint unsigned NOT NULL,
  `purchase_order_item_id` bigint unsigned DEFAULT NULL,
  `grn_item_id` bigint unsigned DEFAULT NULL,
  `material_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hsn_sac` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `rate` decimal(18,4) NOT NULL,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `base_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `cgst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `cgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sgst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `sgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `igst_rate` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `igst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor_bill_items_grn_unique` (`vendor_bill_id`,`grn_item_id`),
  KEY `vendor_bill_items_material_id_foreign` (`material_id`),
  KEY `vendor_bill_items_unit_id_foreign` (`unit_id`),
  KEY `vendor_bill_items_tax_rate_id_foreign` (`tax_rate_id`),
  KEY `vendor_bill_items_grn_item_id_index` (`grn_item_id`),
  KEY `vendor_bill_items_purchase_order_item_id_index` (`purchase_order_item_id`),
  KEY `vendor_bill_items_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `vendor_bill_items_grn_item_id_foreign` FOREIGN KEY (`grn_item_id`) REFERENCES `grn_items` (`id`),
  CONSTRAINT `vendor_bill_items_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `vendor_bill_items_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_bill_items_purchase_order_item_id_foreign` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`),
  CONSTRAINT `vendor_bill_items_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`),
  CONSTRAINT `vendor_bill_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `vendor_bill_items_vendor_bill_id_foreign` FOREIGN KEY (`vendor_bill_id`) REFERENCES `vendor_bills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vendor_bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_bills` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `purchase_order_id` bigint unsigned DEFAULT NULL,
  `bill_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cost_head` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bill_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vendor_invoice_no` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vendor_invoice_no_normalized` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vendor_invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `place_of_supply_state` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `cgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `sgst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `igst_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tds_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `tds_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `net_payable` decimal(18,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `processed_by` bigint unsigned DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `processing_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `processing_notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `live_key` tinyint unsigned GENERATED ALWAYS AS ((case when (`deleted_at` is null) then 1 else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor_bills_company_id_bill_number_unique` (`company_id`,`bill_number`),
  UNIQUE KEY `vendor_bills_invoice_unique` (`vendor_id`,`vendor_invoice_no_normalized`,`live_key`),
  KEY `vendor_bills_task_id_foreign` (`task_id`),
  KEY `vendor_bills_boq_item_id_foreign` (`boq_item_id`),
  KEY `vendor_bills_approved_by_foreign` (`approved_by`),
  KEY `vendor_bills_created_by_foreign` (`created_by`),
  KEY `vendor_bills_updated_by_foreign` (`updated_by`),
  KEY `vendor_bills_deleted_by_foreign` (`deleted_by`),
  KEY `vendor_bills_project_id_status_index` (`project_id`,`status`),
  KEY `vendor_bills_purchase_order_id_index` (`purchase_order_id`),
  KEY `vendor_bills_processed_by_foreign` (`processed_by`),
  KEY `vendor_bills_vendor_id_fk_support` (`vendor_id`),
  CONSTRAINT `vendor_bills_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_bills_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_bills_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `vendor_bills_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_bills_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_bills_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_bills_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `vendor_bills_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`),
  CONSTRAINT `vendor_bills_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_bills_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_bills_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vendor_quotation_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_quotation_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `vendor_quotation_id` bigint unsigned NOT NULL,
  `rfq_item_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `rate` decimal(18,4) NOT NULL,
  `discount_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `base_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `tax_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `tax_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor_quotation_items_vendor_quotation_id_rfq_item_id_unique` (`vendor_quotation_id`,`rfq_item_id`),
  KEY `vendor_quotation_items_rfq_item_id_foreign` (`rfq_item_id`),
  KEY `vendor_quotation_items_tax_rate_id_foreign` (`tax_rate_id`),
  CONSTRAINT `vendor_quotation_items_rfq_item_id_foreign` FOREIGN KEY (`rfq_item_id`) REFERENCES `rfq_items` (`id`),
  CONSTRAINT `vendor_quotation_items_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`),
  CONSTRAINT `vendor_quotation_items_vendor_quotation_id_foreign` FOREIGN KEY (`vendor_quotation_id`) REFERENCES `vendor_quotations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vendor_quotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_quotations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `rfq_id` bigint unsigned NOT NULL,
  `vendor_id` bigint unsigned NOT NULL,
  `quotation_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quotation_date` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `delivery_days` smallint unsigned DEFAULT NULL,
  `payment_terms` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warranty` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `freight_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `other_charges` decimal(18,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(18,2) NOT NULL DEFAULT '0.00',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `is_selected` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor_quotations_rfq_id_vendor_id_unique` (`rfq_id`,`vendor_id`),
  KEY `vendor_quotations_company_id_foreign` (`company_id`),
  KEY `vendor_quotations_vendor_id_foreign` (`vendor_id`),
  KEY `vendor_quotations_created_by_foreign` (`created_by`),
  KEY `vendor_quotations_updated_by_foreign` (`updated_by`),
  KEY `vendor_quotations_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `vendor_quotations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `vendor_quotations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_quotations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_quotations_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`),
  CONSTRAINT `vendor_quotations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendor_quotations_vendor_id_foreign` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gstin` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_terms` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_no` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_ifsc` varchar(11) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendors_company_id_code_unique` (`company_id`,`code`),
  KEY `vendors_created_by_foreign` (`created_by`),
  KEY `vendors_updated_by_foreign` (`updated_by`),
  KEY `vendors_deleted_by_foreign` (`deleted_by`),
  KEY `vendors_company_id_name_index` (`company_id`,`name`),
  KEY `vendors_company_id_gstin_index` (`company_id`,`gstin`),
  CONSTRAINT `vendors_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `vendors_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendors_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendors_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `site_id` bigint unsigned DEFAULT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehouses_company_id_code_unique` (`company_id`,`code`),
  KEY `warehouses_project_id_foreign` (`project_id`),
  KEY `warehouses_site_id_foreign` (`site_id`),
  KEY `warehouses_created_by_foreign` (`created_by`),
  KEY `warehouses_updated_by_foreign` (`updated_by`),
  KEY `warehouses_deleted_by_foreign` (`deleted_by`),
  KEY `warehouses_company_id_project_id_index` (`company_id`,`project_id`),
  CONSTRAINT `warehouses_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `warehouses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `warehouses_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `warehouses_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `warehouses_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`),
  CONSTRAINT `warehouses_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weighbridge_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `weighbridge_receipts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `weighable_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `weighable_id` bigint unsigned NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slip_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weighed_at` datetime DEFAULT NULL,
  `vehicle_no` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gross_weight` decimal(18,4) DEFAULT NULL,
  `tare_weight` decimal(18,4) DEFAULT NULL,
  `net_weight` decimal(18,4) DEFAULT NULL,
  `weight_unit` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kg',
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `manual_reason` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `weighbridge_receipts_company_id_foreign` (`company_id`),
  KEY `weighbridge_receipts_created_by_foreign` (`created_by`),
  KEY `weighbridge_receipts_updated_by_foreign` (`updated_by`),
  KEY `weighbridge_receipts_weighable_type_weighable_id_index` (`weighable_type`,`weighable_id`),
  CONSTRAINT `weighbridge_receipts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `weighbridge_receipts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `weighbridge_receipts_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_order_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_cost_head_id` bigint unsigned DEFAULT NULL,
  `work_order_id` bigint unsigned NOT NULL,
  `boq_item_id` bigint unsigned DEFAULT NULL,
  `boq_line_uid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_id` bigint unsigned DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `rate` decimal(18,4) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `certified_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_order_items_boq_item_id_foreign` (`boq_item_id`),
  KEY `work_order_items_task_id_foreign` (`task_id`),
  KEY `work_order_items_unit_id_foreign` (`unit_id`),
  KEY `work_order_items_work_order_id_index` (`work_order_id`),
  KEY `work_order_items_boq_line_uid_index` (`boq_line_uid`),
  KEY `work_order_items_project_cost_head_id_index` (`project_cost_head_id`),
  CONSTRAINT `work_order_items_boq_item_id_foreign` FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_order_items_project_cost_head_id_foreign` FOREIGN KEY (`project_cost_head_id`) REFERENCES `project_cost_heads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_order_items_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_order_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `work_order_items_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_order_milestones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_order_milestones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint unsigned NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_date` date DEFAULT NULL,
  `amount_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `achieved_at` timestamp NULL DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_order_milestones_work_order_id_index` (`work_order_id`),
  CONSTRAINT `work_order_milestones_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `subcontractor_id` bigint unsigned NOT NULL,
  `wo_number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `wo_date` date NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_profile` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pdf_hide_quantities` tinyint(1) NOT NULL DEFAULT '0',
  `scope` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `contractor_scope` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `company_scope` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payment_terms` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `retention_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `advance_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `tax_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `tds_percent` decimal(7,4) NOT NULL DEFAULT '0.0000',
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_value` decimal(18,2) NOT NULL DEFAULT '0.00',
  `terms` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `safety_terms` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `penalty_terms` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `delay_penalty_per_day` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maximum_penalty_percent` decimal(8,4) DEFAULT NULL,
  `document_terms_template_id` bigint unsigned DEFAULT NULL,
  `terms_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_orders_company_id_wo_number_unique` (`company_id`,`wo_number`),
  KEY `work_orders_subcontractor_id_foreign` (`subcontractor_id`),
  KEY `work_orders_tax_rate_id_foreign` (`tax_rate_id`),
  KEY `work_orders_approved_by_foreign` (`approved_by`),
  KEY `work_orders_closed_by_foreign` (`closed_by`),
  KEY `work_orders_created_by_foreign` (`created_by`),
  KEY `work_orders_updated_by_foreign` (`updated_by`),
  KEY `work_orders_deleted_by_foreign` (`deleted_by`),
  KEY `work_orders_project_id_subcontractor_id_index` (`project_id`,`subcontractor_id`),
  KEY `work_orders_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `work_orders_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_orders_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_orders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `work_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_orders_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_orders_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `work_orders_subcontractor_id_foreign` FOREIGN KEY (`subcontractor_id`) REFERENCES `subcontractors` (`id`),
  CONSTRAINT `work_orders_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`),
  CONSTRAINT `work_orders_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_10_01_115341_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_10_01_115342_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_10_01_200000_create_companies_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_10_01_200100_add_tenancy_to_users_and_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_10_01_200200_create_master_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_10_01_200300_create_project_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_10_01_200400_create_engine_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_10_02_100000_create_rate_analysis_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_10_02_100100_create_boq_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_10_02_100200_create_project_budget_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_10_02_100300_create_planning_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_10_03_100000_create_material_request_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_10_03_100100_create_rfq_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_10_03_100200_create_purchase_order_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_10_03_100300_create_grn_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_10_04_100000_create_stock_ledger_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_10_04_100100_create_material_issue_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_10_04_100200_create_stock_transfer_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_10_04_100300_create_material_return_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_10_04_100400_create_stock_adjustment_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_10_04_100500_seed_inventory_approval_workflows',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_10_04_100600_convert_inventory_tables_to_innodb',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_10_05_100000_create_site_diary_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_10_05_100100_create_dpr_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_10_05_100200_create_progress_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_10_05_100300_seed_dpr_approval_workflows',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_10_06_100000_create_labour_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_10_06_100100_create_subcontract_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_10_06_100200_create_equipment_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_10_06_100300_add_posting_ref_to_project_cost_ledger',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_10_07_100000_create_finance_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_10_07_100100_create_crm_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_10_07_100200_add_paid_amount_to_subcontract_and_labour_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_10_07_100300_seed_finance_approval_workflows',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_10_08_100000_create_quality_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_10_08_100100_create_drawing_and_document_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_10_09_100000_create_reporting_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_10_10_100000_add_company_favicon_and_presence',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_10_10_100100_create_chat_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_10_10_100200_create_device_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_10_10_120000_create_upload_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_10_10_160000_create_file_previews_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_10_10_180000_create_external_cad_preview_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_10_10_190000_drop_external_cad_preview_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_10_11_100000_create_tally_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_10_12_120000_add_two_factor_columns_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_10_24_100000_add_uuid_to_grns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_10_24_120000_add_sync_uuid_to_labour_attendance',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_10_24_120100_drop_crew_attendance_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_11_01_100000_create_boq_drawing_revisions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_11_02_150000_create_data_imports_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_11_02_160000_add_context_to_data_imports_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_11_02_180000_create_ai_assistant_tables',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_11_02_190000_create_user_tutorial_progress_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_11_04_150000_create_boq_measurement_tables',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_11_04_160000_create_boq_cost_components_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_11_04_170000_create_boq_material_norms_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_11_04_180000_create_boq_bbs_tables',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_11_04_190000_create_boq_variation_tables',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_11_05_100000_add_source_import_id_to_boqs_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_11_05_100000_stage9a_formula_coefficient_engine',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_11_05_120000_relabel_legacy_22_7_circle_mode',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_11_05_140000_create_boq_item_material_costs_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_11_05_220000_add_gst_and_source_trace_to_boq_items',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_11_05_221000_make_boq_items_gst_rate_nullable',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_11_06_220000_procurement_equipment_gate_pass',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_11_10_065229_add_measurement_book_to_subcontractor_bills_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_11_10_065231_add_invoice_processing_to_vendor_and_subcontractor_bills',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_11_10_065232_add_vendor_invoice_normalized_to_vendor_bills_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_11_10_065233_create_approval_delegations_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_11_10_070438_frd_gap_closure_batch2_schema',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_11_10_131200_add_boq_material_sync_to_project_budgets',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_11_11_070000_add_equipment_usage_times_and_quantity_tracking',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_11_12_080000_create_scrap_management_tables',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_11_13_100000_add_work_order_pdf_and_terms_templates',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_11_13_120000_create_electricity_management_tables',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_11_13_140000_extend_dpr_for_direct_entry',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_11_13_160000_add_hold_quantity_to_project_tasks',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_11_13_130000_add_historical_import_fields_to_electricity_tables',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_11_13_150000_add_civil_po_document_fields_to_purchase_orders',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_11_14_023233_create_boq_rate_comparison_quotes_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_11_14_024204_create_boq_planning_maps_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_11_14_031415_add_needs_review_to_boq_planning_maps_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_11_14_051002_add_menu_items_to_company_user_table',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_11_14_172619_add_technical_approval_snapshot_to_boqs_table',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_11_14_172620_rename_boq_approval_workflow_steps_to_technical_commercial',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_11_15_171929_create_boq_revision_change_orders_table',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_11_16_062414_add_simple_measurement_fields_to_boq_items_table',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_11_16_062415_add_tracking_basis_to_boq_planning_maps_table',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_11_16_062737_add_boq_quantity_fields_to_progress_and_dpr_tables',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_11_17_110551_create_project_cost_heads_table',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_11_17_110552_add_project_cost_head_id_to_cost_documents',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_11_17_110553_create_project_cash_flow_plans_table',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_11_18_031149_add_planning_milestone_schedule_fields_to_project_milestones_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_11_18_031150_add_source_fields_to_material_requests_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_11_18_031151_add_daily_target_qty_to_project_tasks_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_11_18_031152_create_concrete_mix_profiles_tables',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_11_18_031153_add_concrete_mix_profile_id_to_boq_items_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_11_18_044455_add_project_cost_head_id_to_upstream_documents',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_11_18_044456_create_material_category_wbs_maps_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_11_18_044457_add_parent_id_to_project_cost_heads_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_11_18_051906_widen_cache_key_columns',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_11_18_100000_add_is_primary_to_boq_planning_maps_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_11_18_074933_add_company_role_id_to_project_users_table',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_11_18_095026_add_measurement_nob_to_boq_items_table',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_11_18_111955_add_budget_type_to_project_budgets_table',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_11_19_113320_update_boq_commercial_approver_to_civil_lead',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_11_20_092317_deactivate_boq_planning_maps_for_soft_deleted_tasks',25);
