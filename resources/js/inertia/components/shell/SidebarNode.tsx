import React, { useEffect, useState } from 'react';
import type { NavNode } from '../../types/page';
import { cn } from '../../lib/utils';
import { type ActiveContext, buildActiveContext, isBranchActive, isSelfActive } from './sidebarActive';

// active 判定的純函式已抽到 `sidebarActive.ts`（環節 4d-2；那裡有測試）。
// 這裡 re-export 是為了不動既有的 `import SidebarNode, { buildActiveContext } from './SidebarNode'`。
export { buildActiveContext, isBranchActive };
export type { ActiveContext };

interface SidebarNodeProps {
    node: NavNode;
    ctx: ActiveContext;
    depth?: number;
}

export default function SidebarNode({ node, ctx, depth = 0 }: SidebarNodeProps) {
    // label/badge.label 已由後端 Navigation 解析為當前語系字串，直接顯示。
    const hasChildren = node.children.length > 0;
    const branchActive = isBranchActive(node, ctx);
    const selfActive = isSelfActive(node, ctx);
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

    const hasLink = !!node.href && node.href !== '#';

    if (hasChildren) {
        return (
            <li>
                {/* 導覽（標籤連結）與展開切換（角形鈕）分離：父節點若有實際連結，點標籤導覽、
                    點角形鈕只切換展開且不導覽（preventDefault），否則「點父項導覽會重渲染、
                    active 分支被 useEffect 重新展開」導致使用者永遠無法收起該選單。 */}
                <div className={linkClass} style={indentStyle}>
                    {hasLink ? (
                        <a href={node.href!} className="flex flex-1 items-center gap-2 min-w-0">
                            <i className={cn('w-4 text-center', node.icon)} aria-hidden />
                            <span className="flex-1 truncate">{label}</span>
                        </a>
                    ) : (
                        <button
                            type="button"
                            className="flex flex-1 items-center gap-2 min-w-0 text-left"
                            onClick={() => setOpen((v) => !v)}
                        >
                            <i className={cn('w-4 text-center', node.icon)} aria-hidden />
                            <span className="flex-1 truncate">{label}</span>
                        </button>
                    )}
                    <button
                        type="button"
                        aria-label="toggle submenu"
                        aria-expanded={open}
                        className="ml-1 shrink-0 px-1"
                        onClick={(e) => { e.preventDefault(); e.stopPropagation(); setOpen((v) => !v); }}
                    >
                        <i
                            className={cn('fas fa-angle-left transition-transform', open && '-rotate-90')}
                            aria-hidden
                        />
                    </button>
                </div>
                {open && (
                    <ul className="space-y-1">
                        {node.children.map((child) => (
                            <SidebarNode
                                key={child.key}
                                node={child}
                                ctx={ctx}
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
