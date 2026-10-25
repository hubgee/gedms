<?php

/*
|--------------------------------------------------------------------------
| Sidebar Navigation Configuration
|--------------------------------------------------------------------------
|
| All sidebar menu items are defined here. To add a new page to the sidebar:
|   1. Add a route + controller for the page
|   2. Add an entry below with the required permission
|   3. Done — the sidebar renders automatically
|
| Each item supports:
|   'label'          => Display text
|   'route'          => Named route
|   'icon'           => Array of SVG <path> d-attributes
|   'active'         => Route pattern(s) for active state (string or array)
|   'active_class'   => Extra CSS class when active (e.g. 'drive')
|   'permission'     => Permission name (string), or array = any match grants access
|                       null/omitted = visible to all authenticated users
|   'hide_for_dept_admin' => true to hide from department admins
|   'route_requires' => Permission needed for primary route; if absent, use route_fallback
|   'route_fallback' => Fallback named route when route_requires check fails
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Icon Library — reusable SVG path definitions
    |----------------------------------------------------------------------
    | Referenced by name in menu items. Each value is an array of SVG
    | <path> d-attributes (supports multi-path icons like the gear+circle).
    */
    'icons' => [
        'dashboard'      => ['M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
        'folder'         => ['M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'],
        'document'       => ['M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        'mail'           => ['M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
        'book'           => ['M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
        'clipboard-check'=> ['M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
        'building'       => ['M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
        'users'          => ['M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        'sections'       => ['M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
        'shield'         => ['M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
        'clipboard-list' => ['M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
        'chart'          => ['M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
        'audit'          => ['M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        'settings'       => ['M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z', 'M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
        'tag'            => ['M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
        'lock'           => ['M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'],
        'clock'          => ['M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
    ],

    /*
    |----------------------------------------------------------------------
    | Main Navigation
    |----------------------------------------------------------------------
    */
    'main' => [
        [
            'label'  => 'Dashboard',
            'route'  => 'dashboard',
            'icon'   => 'dashboard',
            'active' => 'dashboard',
        ],
        [
            'label'        => 'Drive',
            'route'        => 'files.index',
            'icon'         => 'folder',
            'active'       => 'files.*',
            'active_class' => 'drive',
            'permission'   => 'view-files',
        ],
        [
            'label'  => 'EDMS',
            'route'  => 'documents.index',
            'icon'   => 'document',
            'active' => 'documents.*',
            'permission' => 'create-documents',
        ],
        [
            'label'  => 'Memos',
            'route'  => 'memos.index',
            'icon'   => 'mail',
            'active' => 'memos.*',
            'permission' => 'create-memos',
        ],
        [
            'label'      => 'User Manual',
            'route'      => 'manual',
            'icon'       => 'book',
            'active'     => 'manual',
            'permission' => 'view manual',
        ],
        [
            'label'              => 'Pending Approvals',
            'route'              => 'approvals.index',
            'icon'               => 'clipboard-check',
            'active'             => 'approvals.*',
            'permission'         => 'approve-documents',
            'hide_for_dept_admin'=> true,
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Administration — Department Admin view
    |----------------------------------------------------------------------
    | Shown when user has manage-department but NOT manage-system.
    */
    'admin_dept' => [
        [
            'label'      => 'Dept. Overview',
            'route'      => 'admin.department.index',
            'icon'       => 'building',
            'active'     => 'admin.department.index',
            'permission' => 'manage-department',
        ],
        [
            'label'      => 'Users',
            'route'      => 'admin.users.index',
            'icon'       => 'users',
            'active'     => 'admin.users*',
            'permission' => 'manage-users',
        ],
        [
            'label'      => 'Sections',
            'route'      => 'admin.sections.index',
            'icon'       => 'sections',
            'active'     => 'admin.sections*',
            'permission' => 'manage-department',
        ],
        [
            'label'      => 'Roles & Permissions',
            'route'      => 'admin.roles.index',
            'icon'       => 'shield',
            'active'     => 'admin.roles*',
            'permission' => 'manage-roles',
        ],
        [
            'label'      => 'Workflow Settings',
            'route'      => 'admin.workflows.index',
            'icon'       => 'clipboard-list',
            'active'     => 'admin.workflows*',
            'permission' => 'manage-workflows',
        ],
        [
            'label'      => 'Reports',
            'route'      => 'admin.reports.index',
            'icon'       => 'chart',
            'active'     => 'admin.reports*',
            'permission' => 'view-audit-logs',
        ],
        [
            'label'      => 'Audit Logs',
            'route'      => 'admin.audit-logs.index',
            'icon'       => 'audit',
            'active'     => 'admin.audit-logs*',
            'permission' => ['view-audit-logs', 'view-audit-only'],
        ],
        [
            'label'      => 'Settings',
            'route'      => 'admin.department.settings',
            'icon'       => 'settings',
            'active'     => 'admin.department.settings',
            'permission' => 'manage-department',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Administration — System Admin / other roles view
    |----------------------------------------------------------------------
    | Shown when user is NOT a department admin but holds admin permissions.
    */
    'admin_system' => [
        [
            'label'           => 'Admin',
            'route'           => 'admin.index',
            'route_requires'  => 'manage-system',
            'route_fallback'  => 'admin.department.index',
            'icon'            => 'settings',
            'active'          => ['admin.index', 'admin.department*'],
        ],
        [
            'label'      => 'Roles & Permissions',
            'route'      => 'admin.roles.index',
            'icon'       => 'shield',
            'active'     => 'admin.roles.*',
            'permission' => 'manage-roles',
        ],
        [
            'label'      => 'Document Types',
            'route'      => 'admin.document-types.index',
            'icon'       => 'tag',
            'active'     => 'admin.document-types.*',
            'permission' => 'manage-document-types',
        ],
        [
            'label'      => 'Sensitivity Levels',
            'route'      => 'admin.sensitivity-levels.index',
            'icon'       => 'lock',
            'active'     => 'admin.sensitivity-levels.*',
            'permission' => 'manage-sensitivity-levels',
        ],
        [
            'label'      => 'Workflows',
            'route'      => 'admin.workflows.index',
            'icon'       => 'clipboard-list',
            'active'     => 'admin.workflows.*',
            'permission' => 'manage-workflows',
        ],
        [
            'label'      => 'Retention Rules',
            'route'      => 'admin.retention-rules.index',
            'icon'       => 'clock',
            'active'     => 'admin.retention-rules.*',
            'permission' => ['manage-retention-disposition', 'manage-retention'],
        ],
        [
            'label'      => 'Audit Logs',
            'route'      => 'admin.audit-logs.index',
            'icon'       => 'audit',
            'active'     => 'admin.audit-logs*',
            'permission' => ['view-audit-logs', 'view-audit-only'],
        ],
        [
            'label'      => 'Storage Spaces',
            'route'      => 'admin.storage-spaces.index',
            'icon'       => 'settings',
            'active'     => 'admin.storage-spaces*',
            'permission' => 'manage-storage-spaces',
        ],
    ],

];
