import React, { useEffect, useState } from 'react';
import type { NavNode } from '../../types/page';
import { cn } from '../../lib/utils';

/** 取 href 的 pathname（去掉 origin 與 query），用於 active 比對。 */
function pathOf(href: string | null): string | null {
    if (!href || href === '#') {
        return null;
    }
    try {
        // href 可能是相對或絕對；以目前 origin 解析。
        return new URL(href, window.location.origin).pathname.replace(/\/$/, '') || '/';
    } catch {
        return href;
    }
}

function normalize(path: string): string {
    return path.replace(/\/$/, '') || '/';
}

/**
 * 節點自身是否對應目前路徑。葉節點採精確比對（避免 /codes 在 /codes/X 上誤亮）。
 *
 * 註：React 端以 href 路徑判定 active（後端 nav 的 active.patterns 為 route 名稱
 * glob，僅供 Blade 以 request()->routeIs() 使用；React 無 route 名稱對照表，故改用
 * href 路徑比對，見遷移計畫附錄 D 決策記錄）。
 */
function isSelfActive(node: NavNode, currentPath: string): boolean {
    const p = pathOf(node.href);
    return p !== null && p === currentPath;
}

/** 父節點是否落在目前路徑的祖先（href 為 currentPath 的前綴段）。 */
function isAncestorOf(node: NavNode, currentPath: string): boolean {
    const p = pathOf(node.href);
    return p !== null && p !== '/' && (currentPath === p || currentPath.startsWith(p + '/'));
}

/**
 * 節點是否應高亮 / 父節點是否應展開：自身精確命中，或任一子孫命中，
 * 或（針對有子節點的區段）目前路徑落在其 href 之下（細項頁高亮其區段）。
 */
export function isBranchActive(node: NavNode, currentPath: string): boolean {
    if (isSelfActive(node, currentPath)) {
        return true;
    }
    if (node.children.length > 0 && isAncestorOf(node, currentPath)) {
        return true;
    }
    return node.children.some((child) => isBranchActive(child, currentPath));
}

interface SidebarNodeProps {
    node: NavNode;
    currentPath: string;
    depth?: number;
}

export default function SidebarNode({ node, currentPath, depth = 0 }: SidebarNodeProps) {
    // label/badge.label 已由後端 Navigation 解析為當前語系字串，直接顯示。
    const hasChildren = node.children.length > 0;
    const branchActive = isBranchActive(node, currentPath);
    const selfActive = isSelfActive(node, currentPath);
    const [open, setOpen] = useState<boolean>(branchActive);

    // 導覽後若此分支變為 active 但仍收合（持久化 layout 不會 remount），自動展開。
    useEffect(() => {
        if (branchActive) {
            setOpen(true);
        }
    }, [branchActive]);

    const label = node.label;
    const indentStyle: React.CSSProperties = depth > 0 ? { paddingLeft: 12 + depth * 12 } : {};

    const linkClass = cn(
        'flex items-center gap-2 rounded px-3 py-2 text-sm transition-colors',
        'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
        (selfActive || (hasChildren && branchActive)) &&
            'bg-sidebar-primary text-sidebar-primary-foreground hover:bg-sidebar-primary'
    );

    if (hasChildren) {
        return (
            <li>
                <a
                    href={node.href ?? '#'}
                    className={linkClass}
                    style={indentStyle}
                    onClick={(e) => {
                        // 父節點：點擊切換展開（若無實際連結）；有連結則同時導覽。
                        if (!node.href || node.href === '#') {
                            e.preventDefault();
                        }
                        setOpen((v) => !v);
                    }}
                >
                    <i className={cn('w-4 text-center', node.icon)} aria-hidden />
                    <span className="flex-1">{label}</span>
                    <i
                        className={cn('fas fa-angle-left transition-transform', open && '-rotate-90')}
                        aria-hidden
                    />
                </a>
                {open && (
                    <ul className="space-y-1">
                        {node.children.map((child) => (
                            <SidebarNode
                                key={child.key}
                                node={child}
                                currentPath={currentPath}
                                depth={depth + 1}
                            />
                        ))}
                    </ul>
                )}
            </li>
        );
    }

    return (
        <li>
            <a href={node.href ?? '#'} className={linkClass} style={indentStyle}>
                <i className={cn('w-4 text-center', node.icon)} aria-hidden />
                <span className="flex-1">
                    {label}
                    {node.suffix ? <small className="ml-1 opacity-70">{node.suffix}</small> : null}
                </span>
                {node.badge && node.badge.show ? (
                    <span className="rounded bg-yellow-400 px-1.5 py-0.5 text-xs font-semibold text-yellow-900">
                        {node.badge.label}
                    </span>
                ) : null}
            </a>
        </li>
    );
}
