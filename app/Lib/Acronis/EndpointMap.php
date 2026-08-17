<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Acronis;

/**
 * Contrato dos endpoints Acronis usados pela camada de Services.
 *
 * Fontes consultadas em 2026-08-04:
 * - OAuth Client Credentials: /api/2/idp/token.
 * - Account Management API v2: /api/2/tenants, /api/2/clients/{client_id}, /api/2/tenants/{tenant_id}/usages.
 * - Workload Management API v5: /api/workload_management/v5/workloads.
 * - Alert Manager API v1: /api/alert_manager/v1/alerts.
 * - Task Manager API v2: /api/task_manager/v2/tasks.
 * - Resource Management API v4: /api/resource_management/v4/resource_statuses?type=resource.machine.
 */
final class EndpointMap
{
    public static function serviceMap(): array
    {
        return [
            'DashboardService' => [
                'clientes' => 'GET /api/2/tenants -> items[*].id|uuid|name|kind|enabled',
                'dispositivos' => 'GET /api/workload_management/v5/workloads?include_status=true&include_all_attributes=true -> items[*]',
                'backups' => 'GET /api/task_manager/v2/tasks -> items[*].state|result.code|completedAt|updatedAt|context.Persistent.Name',
                'espaco_utilizado' => 'GET /api/2/tenants/{tenant_id}/usages -> offering_items/usages conforme contrato do tenant',
                'status_protecao' => 'GET /api/resource_management/v4/resource_statuses?type=resource.machine&include_attributes=true -> items[*].status|attributes',
            ],
            'AlertService' => [
                'alertas' => 'GET /api/alert_manager/v1/alerts?order=desc(created_at) -> items[*].tenant|resourceName|severity|details.title|details.description|createdAt|updatedAt|deletedAt',
            ],
            'DeviceService' => [
                'devices' => 'GET /api/workload_management/v5/workloads?include_status=true&include_all_attributes=true -> items[*].name|attributes.os|attributes.ip|attributes.plan_name|enabled|updated_at|agent_id',
                'agent_version' => 'GET /api/agent_manager/v2/agents quando disponivel -> items[*].version por agent_id',
                'last_backup' => 'GET /api/resource_management/v4/resource_statuses?type=resource.machine&include_attributes=true -> attributes.last_successful_backup|backup_status',
            ],
            'CustomerService' => [
                'clientes' => 'GET /api/2/tenants -> items[*].name|id|uuid|parent_id|kind',
                'devices_por_cliente' => 'GET /api/workload_management/v5/workloads?tenant_id={tenant_id} -> items count',
                'plano' => 'GET /api/2/tenants/{tenant_id}/usages -> offering item/edition quando retornado pela conta',
                'ultimo_backup' => 'GET /api/task_manager/v2/tasks com filtro por tenant quando disponivel -> max completedAt/updatedAt',
            ],
        ];
    }
}
