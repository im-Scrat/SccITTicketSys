# floor-plan — the Floorplan (Phase 2.8, completed Phase 2.11)

Administrator-only. Routes: `/app/floor-plan` (overview) and
`/app/floor-plan/rooms/:id` (editor), both behind `RequireFloorPlan`
(Administrator role + `floorplan.view`); the server decides every request.

| Piece | Where |
|---|---|
| Overview: room cards, totals, filters, "New room" (Locations' own form) | `pages/FloorPlanPage.tsx`, `components/RoomCard.tsx`, `components/RoomThumbnail.tsx` |
| Editor workspace: header, status chips, save state, live + presence, panels, dialogs | `pages/FloorPlanRoomPage.tsx` |
| SVG canvas: pan/zoom/pinch, drag and group drag, resize/turn handles, drop targets | `components/FloorPlanCanvas.tsx`, `PcNode.tsx`, `FixtureShape.tsx` |
| Workstations panel (this room / other rooms with a confirmed move) | `components/WorkstationsPanel.tsx` |
| Details panel (asset record, tickets, repairs; fixture properties) | `components/DetailsPanel.tsx` |
| Toolbar, dialogs, status badges | `EditorToolbar.tsx`, `EditorDialogs.tsx`, `EditorStatus.tsx`, `SetupLayout.tsx` |
| Draft model, diff, three-way merge | `lib/layoutDocument.ts` |
| Geometry (mirrors the server's `LayoutGeometry`) | `lib/geometry.ts` |
| Per-room editor store (draft, undo/redo, selection, view) | `stores/createEditorStore.ts`, `EditorStoreContext.tsx`, `useEditor.ts` |
| Unsaved-draft storage (sessionStorage) and leave guard | `lib/draftStorage.ts`, `hooks/useUnsavedChangesGuard.ts` |
| Realtime notices and presence | `hooks/useFloorPlanChannel.ts`, `@/hooks/usePresenceChannel.ts` |

PC status colours come from the shared table `@/lib/pcStatus` via
`PcStatusBadge` — never mapped locally. Nothing here claims a PC is online or
offline.
