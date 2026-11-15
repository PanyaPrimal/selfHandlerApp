package app.selfhandler.mobile.widget;

import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * Checklist layout shared with the web list: open items first as a tree in manual order, then
 * the finished ones as their own tree. An item starts a branch when its parent is not in the
 * same section. The same cascade as the server is applied optimistically to a tap.
 */
final class WidgetTree {
    static final int MAX_DEPTH = 3;

    /** One widget row: a task at a visual depth, or the "Done: N" divider (task == null). */
    static final class Row {
        final WidgetTask task;
        final int depth;
        final int doneCount;

        Row(WidgetTask task, int depth, int doneCount) {
            this.task = task;
            this.depth = depth;
            this.doneCount = doneCount;
        }
    }

    private static final Comparator<WidgetTask> ORDER = (left, right) -> {
        int position = Integer.compare(left.position, right.position);
        return position != 0 ? position : Long.compare(left.id, right.id);
    };

    private WidgetTree() {
    }

    static List<Row> rows(List<WidgetTask> tasks) {
        List<Row> rows = new ArrayList<>();
        List<Row> open = section(tasks, false);
        List<Row> done = section(tasks, true);
        rows.addAll(open);
        if (!done.isEmpty()) {
            rows.add(new Row(null, 0, done.size()));
            rows.addAll(done);
        }
        return rows;
    }

    static int openCount(List<WidgetTask> tasks) {
        int count = 0;
        for (WidgetTask task : tasks) if (!task.done) count++;
        return count;
    }

    private static List<Row> section(List<WidgetTask> tasks, boolean done) {
        Map<Long, List<WidgetTask>> children = new HashMap<>();
        Set<Long> members = new HashSet<>();
        List<WidgetTask> roots = new ArrayList<>();
        for (WidgetTask task : tasks) if (task.done == done) members.add(task.id);
        for (WidgetTask task : tasks) {
            if (task.done != done) continue;
            if (task.parentId != 0L && members.contains(task.parentId)) {
                List<WidgetTask> list = children.get(task.parentId);
                if (list == null) children.put(task.parentId, list = new ArrayList<>());
                list.add(task);
            } else {
                roots.add(task);
            }
        }
        Collections.sort(roots, ORDER);
        List<Row> rows = new ArrayList<>();
        for (WidgetTask root : roots) visit(root, 0, children, rows);
        return rows;
    }

    private static void visit(WidgetTask task, int depth, Map<Long, List<WidgetTask>> children, List<Row> rows) {
        rows.add(new Row(task, depth, 0));
        List<WidgetTask> below = children.get(task.id);
        if (below == null) return;
        Collections.sort(below, ORDER);
        for (WidgetTask child : below) visit(child, Math.min(depth + 1, MAX_DEPTH - 1), children, rows);
    }

    /** The server's checkbox cascade, applied locally so a tap shows its result at once. */
    static List<WidgetTask> toggle(List<WidgetTask> tasks, long id, boolean done) {
        Map<Long, WidgetTask> byId = new HashMap<>();
        for (WidgetTask task : tasks) byId.put(task.id, task);
        WidgetTask target = byId.get(id);
        if (target == null) return tasks;
        byId.put(id, target.withDone(done));

        Set<Long> below = new HashSet<>();
        Set<Long> level = Collections.singleton(id);
        for (int step = 0; step < MAX_DEPTH && !level.isEmpty(); step++) {
            Set<Long> next = new HashSet<>();
            for (WidgetTask task : tasks) if (level.contains(task.parentId)) next.add(task.id);
            below.addAll(next);
            level = next;
        }
        for (long child : below) byId.put(child, byId.get(child).withDone(done));

        long parentId = target.parentId;
        for (int step = 0; parentId != 0L && step < MAX_DEPTH; step++) {
            WidgetTask parent = byId.get(parentId);
            if (parent == null) break;
            if (done) {
                boolean open = false;
                for (WidgetTask task : byId.values()) if (task.parentId == parentId && !task.done) open = true;
                if (open || parent.done) break;
                byId.put(parentId, parent.withDone(true));
            } else if (parent.done) {
                byId.put(parentId, parent.withDone(false));
            }
            parentId = parent.parentId;
        }

        List<WidgetTask> result = new ArrayList<>();
        for (WidgetTask task : tasks) result.add(byId.get(task.id));
        return result;
    }
}
