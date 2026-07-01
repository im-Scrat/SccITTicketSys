<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All foreign keys, added after every table exists.
 *
 * Decoupling FK creation from table creation removes ordering/circular
 * dependencies and centralizes the ON DELETE policy:
 *   restrict = protect in-use reference data
 *   cascade  = true compositions (meaningless without the parent)
 *   set null = optional context + actor/audit columns (preserve history)
 * ON UPDATE is NO ACTION everywhere (surrogate keys are immutable).
 *
 * Each row: [table, column, referenced_table, on_delete].
 */
return new class extends Migration
{
    /**
     * @return array<int, array{0:string,1:string,2:string,3:string}>
     */
    private function relations(): array
    {
        return [
            // Identity & access
            ['users', 'role_id', 'roles', 'restrict'],
            ['users', 'created_by', 'users', 'set null'],
            ['users', 'updated_by', 'users', 'set null'],
            ['role_permissions', 'role_id', 'roles', 'cascade'],
            ['role_permissions', 'permission_id', 'permissions', 'cascade'],
            ['user_permissions', 'user_id', 'users', 'cascade'],
            ['user_permissions', 'permission_id', 'permissions', 'cascade'],
            ['user_permissions', 'granted_by', 'users', 'set null'],

            // Locations & floor plan
            ['buildings', 'created_by', 'users', 'set null'],
            ['buildings', 'updated_by', 'users', 'set null'],
            ['floors', 'building_id', 'buildings', 'cascade'],
            ['floors', 'created_by', 'users', 'set null'],
            ['floors', 'updated_by', 'users', 'set null'],
            ['rooms', 'floor_id', 'floors', 'cascade'],
            ['rooms', 'created_by', 'users', 'set null'],
            ['rooms', 'updated_by', 'users', 'set null'],
            ['room_layouts', 'room_id', 'rooms', 'cascade'],
            ['room_layouts', 'created_by', 'users', 'set null'],
            ['room_layouts', 'updated_by', 'users', 'set null'],
            ['floor_plan_positions', 'room_layout_id', 'room_layouts', 'cascade'],
            ['floor_plan_positions', 'pc_unit_id', 'pc_units', 'cascade'],

            // Computers, specs, QR
            ['pc_units', 'room_id', 'rooms', 'set null'],
            ['pc_units', 'created_by', 'users', 'set null'],
            ['pc_units', 'updated_by', 'users', 'set null'],
            ['pc_specifications', 'pc_unit_id', 'pc_units', 'cascade'],
            ['qr_codes', 'pc_unit_id', 'pc_units', 'cascade'],
            ['qr_codes', 'asset_id', 'assets', 'cascade'],
            ['qr_scan_logs', 'qr_code_id', 'qr_codes', 'set null'],
            ['qr_scan_logs', 'pc_unit_id', 'pc_units', 'set null'],
            ['qr_scan_logs', 'asset_id', 'assets', 'set null'],
            ['qr_scan_logs', 'maintenance_record_id', 'maintenance_records', 'set null'],
            ['qr_scan_logs', 'scanned_by', 'users', 'set null'],

            // Ticketing
            ['ticket_categories', 'default_priority_id', 'ticket_priorities', 'set null'],
            ['tickets', 'reporter_id', 'users', 'restrict'],
            ['tickets', 'assigned_technician_id', 'users', 'set null'],
            ['tickets', 'room_id', 'rooms', 'set null'],
            ['tickets', 'pc_unit_id', 'pc_units', 'set null'],
            ['tickets', 'category_id', 'ticket_categories', 'restrict'],
            ['tickets', 'priority_id', 'ticket_priorities', 'restrict'],
            ['tickets', 'current_status_id', 'ticket_statuses', 'restrict'],
            ['tickets', 'duplicate_of_id', 'tickets', 'set null'],
            ['tickets', 'created_by', 'users', 'set null'],
            ['tickets', 'updated_by', 'users', 'set null'],
            ['ticket_tags', 'ticket_id', 'tickets', 'cascade'],
            ['ticket_tags', 'tag_id', 'tags', 'cascade'],
            ['ticket_updates', 'ticket_id', 'tickets', 'cascade'],
            ['ticket_updates', 'user_id', 'users', 'set null'],
            ['ticket_status_history', 'ticket_id', 'tickets', 'cascade'],
            ['ticket_status_history', 'from_status_id', 'ticket_statuses', 'restrict'],
            ['ticket_status_history', 'to_status_id', 'ticket_statuses', 'restrict'],
            ['ticket_status_history', 'changed_by', 'users', 'set null'],
            ['ticket_votes', 'ticket_id', 'tickets', 'cascade'],
            ['ticket_votes', 'user_id', 'users', 'cascade'],
            ['ticket_comments', 'ticket_id', 'tickets', 'cascade'],
            ['ticket_comments', 'user_id', 'users', 'restrict'],
            ['ticket_comments', 'parent_comment_id', 'ticket_comments', 'set null'],
            ['attachments', 'ticket_id', 'tickets', 'cascade'],
            ['attachments', 'uploaded_by', 'users', 'set null'],

            // Technician & maintenance
            ['technician_assignments', 'ticket_id', 'tickets', 'cascade'],
            ['technician_assignments', 'technician_id', 'users', 'restrict'],
            ['technician_assignments', 'assigned_by', 'users', 'set null'],
            ['maintenance_types', 'default_checklist_template_id', 'checklist_templates', 'set null'],
            ['maintenance_records', 'ticket_id', 'tickets', 'set null'],
            ['maintenance_records', 'pc_unit_id', 'pc_units', 'set null'],
            ['maintenance_records', 'asset_id', 'assets', 'set null'],
            ['maintenance_records', 'technician_id', 'users', 'restrict'],
            ['maintenance_records', 'maintenance_type_id', 'maintenance_types', 'restrict'],
            ['maintenance_records', 'created_by', 'users', 'set null'],
            ['maintenance_records', 'updated_by', 'users', 'set null'],
            ['checklist_templates', 'maintenance_type_id', 'maintenance_types', 'set null'],
            ['checklist_template_items', 'checklist_template_id', 'checklist_templates', 'cascade'],
            ['maintenance_checklists', 'maintenance_record_id', 'maintenance_records', 'cascade'],
            ['maintenance_checklists', 'checklist_template_item_id', 'checklist_template_items', 'set null'],
            ['maintenance_checklists', 'completed_by', 'users', 'set null'],
            ['repair_images', 'maintenance_record_id', 'maintenance_records', 'cascade'],
            ['repair_images', 'uploaded_by', 'users', 'set null'],
            ['maintenance_notes', 'maintenance_record_id', 'maintenance_records', 'cascade'],
            ['maintenance_notes', 'technician_id', 'users', 'set null'],
            ['hardware_replacements', 'maintenance_record_id', 'maintenance_records', 'cascade'],
            ['hardware_replacements', 'pc_unit_id', 'pc_units', 'set null'],
            ['hardware_replacements', 'old_component_id', 'hardware_components', 'restrict'],
            ['hardware_replacements', 'new_component_id', 'hardware_components', 'restrict'],
            ['hardware_replacements', 'new_asset_id', 'assets', 'set null'],

            // Inventory & asset lifecycle
            ['hardware_components', 'manufacturer_id', 'manufacturers', 'set null'],
            ['hardware_models', 'hardware_component_id', 'hardware_components', 'restrict'],
            ['assets', 'hardware_model_id', 'hardware_models', 'restrict'],
            ['assets', 'supplier_id', 'suppliers', 'set null'],
            ['assets', 'current_room_id', 'rooms', 'set null'],
            ['assets', 'created_by', 'users', 'set null'],
            ['assets', 'updated_by', 'users', 'set null'],
            ['consumables', 'hardware_model_id', 'hardware_models', 'set null'],
            ['consumables', 'supplier_id', 'suppliers', 'set null'],
            ['consumables', 'current_room_id', 'rooms', 'set null'],
            ['consumables', 'created_by', 'users', 'set null'],
            ['consumables', 'updated_by', 'users', 'set null'],
            ['stock_transactions', 'consumable_id', 'consumables', 'cascade'],
            ['stock_transactions', 'performed_by', 'users', 'set null'],
            ['asset_status_history', 'asset_id', 'assets', 'cascade'],
            ['asset_status_history', 'changed_by', 'users', 'set null'],
            ['pc_component_installations', 'pc_unit_id', 'pc_units', 'cascade'],
            ['pc_component_installations', 'asset_id', 'assets', 'restrict'],
            ['pc_component_installations', 'installed_by', 'users', 'set null'],
            ['procurement_requests', 'requested_by', 'users', 'restrict'],
            ['procurement_requests', 'approved_by', 'users', 'set null'],
            ['procurement_request_items', 'procurement_request_id', 'procurement_requests', 'cascade'],
            ['procurement_request_items', 'hardware_model_id', 'hardware_models', 'set null'],
            ['asset_transfers', 'asset_id', 'assets', 'cascade'],
            ['asset_transfers', 'from_room_id', 'rooms', 'set null'],
            ['asset_transfers', 'to_room_id', 'rooms', 'set null'],
            ['asset_transfers', 'transferred_by', 'users', 'set null'],
            ['disposal_records', 'asset_id', 'assets', 'cascade'],
            ['disposal_records', 'approved_by', 'users', 'set null'],

            // AI / RAG
            ['ai_analysis_logs', 'ticket_id', 'tickets', 'cascade'],
            ['ai_analysis_logs', 'ai_model_id', 'ai_models', 'set null'],
            ['ai_recommendations', 'ai_analysis_log_id', 'ai_analysis_logs', 'cascade'],
            ['ai_recommendations', 'completed_by', 'users', 'set null'],
            ['ai_conversation_logs', 'ticket_id', 'tickets', 'cascade'],
            ['ai_conversation_logs', 'user_id', 'users', 'set null'],
            ['ai_conversation_logs', 'ai_model_id', 'ai_models', 'set null'],
            ['ai_learning_events', 'maintenance_record_id', 'maintenance_records', 'set null'],
            ['ai_learning_events', 'ticket_id', 'tickets', 'set null'],
            ['ai_learning_events', 'pc_unit_id', 'pc_units', 'set null'],
            ['ai_failure_patterns', 'pc_unit_id', 'pc_units', 'cascade'],
            ['ai_failure_patterns', 'hardware_component_id', 'hardware_components', 'set null'],
            ['ai_knowledge_articles', 'created_from_ticket_id', 'tickets', 'set null'],
            ['ai_knowledge_articles', 'created_by', 'users', 'set null'],
            ['ai_predictions', 'pc_unit_id', 'pc_units', 'cascade'],
            ['ai_predictions', 'ai_model_id', 'ai_models', 'set null'],
            ['ai_feedback', 'ai_recommendation_id', 'ai_recommendations', 'cascade'],
            ['ai_feedback', 'ai_analysis_log_id', 'ai_analysis_logs', 'cascade'],
            ['ai_feedback', 'user_id', 'users', 'cascade'],
            ['ai_embeddings', 'ai_model_id', 'ai_models', 'restrict'],
            ['ai_embedding_sources', 'ai_model_id', 'ai_models', 'restrict'],
            ['ai_system_settings', 'active_model_id', 'ai_models', 'set null'],
            ['ai_system_settings', 'embedding_model_id', 'ai_models', 'set null'],
            ['ai_system_settings', 'updated_by', 'users', 'set null'],

            // Administration & system
            ['notifications', 'user_id', 'users', 'cascade'],
            ['notification_preferences', 'user_id', 'users', 'cascade'],
            ['announcements', 'created_by', 'users', 'set null'],
            ['activity_logs', 'user_id', 'users', 'set null'],
            ['audit_logs', 'user_id', 'users', 'set null'],
            ['login_history', 'user_id', 'users', 'set null'],
            ['system_settings', 'created_by', 'users', 'set null'],
            ['system_settings', 'updated_by', 'users', 'set null'],
            ['dashboard_widgets', 'user_id', 'users', 'cascade'],
            ['maintenance_windows', 'created_by', 'users', 'set null'],
            ['backup_history', 'created_by', 'users', 'set null'],
        ];
    }

    public function up(): void
    {
        foreach ($this->relations() as [$table, $column, $on, $onDelete]) {
            Schema::table($table, function (Blueprint $t) use ($column, $on, $onDelete) {
                $t->foreign($column)
                    ->references('id')->on($on)
                    ->onUpdate('no action')
                    ->onDelete($onDelete);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->relations()) as [$table, $column]) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropForeign([$column]);
            });
        }
    }
};
