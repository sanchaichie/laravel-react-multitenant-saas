<?php

namespace App\Enums;

/**
 * What a role allows in an organization. Policies check these, never role names.
 * Each organization's roles store their own set (App\Models\Role); the owner role
 * has them all, including ones added later.
 */
enum Permission: string
{
    case TasksCreate = 'tasks.create';
    case TasksManage = 'tasks.manage';
    case MembersInvite = 'members.invite';
    case MembersManage = 'members.manage';
    case RolesManage = 'roles.manage';
    case AuditLogView = 'audit_log.view';
    case AiUse = 'ai.use';
    case AiUsageView = 'ai_usage.view';
    case AiBudgetManage = 'ai_budget.manage';
    case BillingManage = 'billing.manage';
    case WebhooksManage = 'webhooks.manage';

    public function label(): string
    {
        return match ($this) {
            self::TasksCreate => 'Create tasks, and edit or delete their own',
            self::TasksManage => 'Edit and delete any task',
            self::MembersInvite => 'Invite people and manage invitations',
            self::MembersManage => 'Change roles and remove members',
            self::RolesManage => 'Create, edit and delete roles',
            self::AuditLogView => 'View the audit log',
            self::AiUse => 'Ask for AI summaries and help',
            self::AiUsageView => 'View AI usage and the budget',
            self::AiBudgetManage => 'Change the monthly AI budget',
            self::BillingManage => 'Manage the plan, payment details and invoices',
            self::WebhooksManage => 'Manage webhooks (they send task content to other services)',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::TasksCreate, self::TasksManage => 'Tasks',
            self::MembersInvite, self::MembersManage => 'Members',
            self::RolesManage => 'Roles',
            self::AuditLogView, self::BillingManage, self::WebhooksManage => 'Organization',
            self::AiUse, self::AiUsageView, self::AiBudgetManage => 'AI',
        };
    }
}
