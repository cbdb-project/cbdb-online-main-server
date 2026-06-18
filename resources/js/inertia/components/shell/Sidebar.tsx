import React from 'react';
import { usePage } from '@inertiajs/react';
import type { NavNode, SharedProps } from '../../types/page';
import SidebarNode from './SidebarNode';

interface SidebarProps {
    collapsed: boolean;
}

/**
 * AdminLTE 風格的深色側邊欄，從 share() 的 nav（單一來源 App\Support\Navigation，
 * 已套用角色閘門 + flag 連結解析）渲染。active 由目前路徑與節點 href 比對。
 */
export default function Sidebar({ collapsed }: SidebarProps) {
    const page = usePage<SharedProps>();
    const nav: NavNode[] = page.props.nav ?? [];
    const appName = 'CBDB';
    const currentPath = (page.url.split('?')[0] || '/').replace(/\/$/, '') || '/';

    return (
        <aside
            className={
                'flex h-screen flex-col bg-sidebar text-sidebar-foreground transition-all duration-200 ' +
                (collapsed ? 'w-0 overflow-hidden md:w-0' : 'w-64')
            }
        >
            <div className="flex h-14 items-center justify-center border-b border-sidebar-border px-4 text-lg font-light text-sidebar-primary-foreground">
                {appName}
            </div>
            <nav className="flex-1 overflow-y-auto px-2 py-3">
                <ul className="space-y-1">
                    {nav.map((node) => (
                        <SidebarNode key={node.key} node={node} currentPath={currentPath} />
                    ))}
                </ul>
            </nav>
        </aside>
    );
}
