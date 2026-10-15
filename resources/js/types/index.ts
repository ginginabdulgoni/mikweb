export type RouterStatus = 'online' | 'offline' | 'unreachable' | 'unknown';

export interface Organization {
    id: number;
    name: string;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    is_super_admin: boolean;
    organization: Organization | null;
    roles: string[];
}

export type Abilities = Record<string, boolean>;

export interface RouterGroupRef {
    id: number;
    name: string;
}

export interface RouterSummary {
    id: number;
    name: string;
    host: string;
    identity: string | null;
    site: string | null;
    isp: string | null;
    status: RouterStatus;
    status_label: string;
    status_color: string;
    routeros_version: string | null;
    hardware_model: string | null;
    last_seen_at: string | null;
    monitoring_enabled: boolean;
    tags: string[];
    groups: RouterGroupRef[];
}

export interface RouterDetail extends RouterSummary {
    preferred_transport: string;
    api_port: number;
    api_ssl_port: number;
    ssh_port: number;
    use_tls: boolean;
    verify_tls: boolean;
    snmp_enabled: boolean;
    snmp_version: string | null;
    snmp_port: number;
    location: string | null;
    latitude: number | null;
    longitude: number | null;
    serial_number: string | null;
    architecture: string | null;
    board_name: string | null;
    poll_interval_seconds: number;
    notes: string | null;
    last_error: string | null;
    credentials: { api: { configured: boolean; username: string | null } };
    capability: {
        routeros_major: number | null;
        supports_rest: boolean;
        has_wifi_package: boolean;
        has_wireless_package: boolean;
        has_capsman: boolean;
    } | null;
    created_at: string | null;
}

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta?: Record<string, unknown>;
    current_page: number;
    last_page: number;
    total: number;
}

export interface ActiveRouter {
    id: number;
    name: string;
    host: string;
    status: RouterStatus;
    status_color: string;
    routeros_major: number | null;
    cpu_load: number | null;
    mem_percent: number | null;
}

export interface RouterPick {
    id: number;
    name: string;
    host: string;
    status_color: string;
}

export interface NavData {
    activeRouter: ActiveRouter | null;
    routers: RouterPick[];
}

export interface PageProps {
    auth: { user: AuthUser | null; can: Abilities };
    flash: { success: string | null; error: string | null };
    app: { name: string };
    nav: NavData;
    [key: string]: unknown;
}
