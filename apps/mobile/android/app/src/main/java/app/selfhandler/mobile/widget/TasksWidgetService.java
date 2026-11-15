package app.selfhandler.mobile.widget;

import android.appwidget.AppWidgetManager;
import android.content.Context;
import android.content.Intent;
import android.graphics.Paint;
import android.view.View;
import android.widget.RemoteViews;
import android.widget.RemoteViewsService;

import java.text.ParseException;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

import app.selfhandler.mobile.R;

/** Supplies one widget's checklist rows from the cache; network work happens in TasksWidgetProvider. */
public class TasksWidgetService extends RemoteViewsService {
    @Override
    public RemoteViewsFactory onGetViewFactory(Intent intent) {
        int widgetId = intent.getIntExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID);
        return new Factory(getApplicationContext(), widgetId);
    }

    private static final class Factory implements RemoteViewsFactory {
        private final Context context;
        private final int widgetId;
        private List<WidgetTree.Row> rows = new ArrayList<>();
        private String today = TasksWidgetProvider.today();
        private int indent;

        Factory(Context context, int widgetId) {
            this.context = context;
            this.widgetId = widgetId;
            this.indent = Math.round(18 * context.getResources().getDisplayMetrics().density);
        }

        @Override
        public void onCreate() {
        }

        @Override
        public void onDataSetChanged() {
            today = TasksWidgetProvider.today();
            String list = TaskWidgetStore.listFor(context, widgetId);
            rows = WidgetTree.rows(TaskWidgetStore.tasks(context, list));
        }

        @Override
        public void onDestroy() {
            rows.clear();
        }

        @Override
        public int getCount() {
            return rows.size();
        }

        @Override
        public RemoteViews getViewAt(int position) {
            if (position < 0 || position >= rows.size()) {
                return new RemoteViews(context.getPackageName(), R.layout.widget_task_item);
            }
            WidgetTree.Row row = rows.get(position);
            if (row.task == null) {
                RemoteViews divider = new RemoteViews(context.getPackageName(), R.layout.widget_done_header);
                divider.setTextViewText(R.id.done_header, context.getString(R.string.widget_done_count, row.doneCount));
                return divider;
            }
            WidgetTask task = row.task;
            RemoteViews view = new RemoteViews(context.getPackageName(), R.layout.widget_task_item);
            view.setViewPadding(R.id.task_row, row.depth * indent, 0, 0, 0);
            view.setTextViewText(R.id.task_title, task.title);
            view.setImageViewResource(R.id.task_check, task.done ? R.drawable.ic_widget_checked : R.drawable.ic_widget_check);
            view.setTextColor(R.id.task_title, context.getColor(task.done ? R.color.widget_muted : R.color.widget_ink));
            view.setInt(R.id.task_title, "setPaintFlags", task.done
                ? Paint.STRIKE_THRU_TEXT_FLAG | Paint.ANTI_ALIAS_FLAG : Paint.ANTI_ALIAS_FLAG);
            view.setViewVisibility(R.id.task_priority, task.isHigh() && !task.done ? View.VISIBLE : View.GONE);
            boolean overdue = !task.done && task.dueOn != null && task.dueOn.compareTo(today) < 0;
            view.setViewVisibility(R.id.task_due, overdue ? View.VISIBLE : View.GONE);
            if (overdue) view.setTextViewText(R.id.task_due, context.getString(R.string.widget_overdue, shortDate(task.dueOn)));
            view.setContentDescription(R.id.task_check, context.getString(
                task.done ? R.string.widget_mark_open : R.string.widget_mark_done, task.title));

            view.setOnClickFillInIntent(R.id.task_check, new Intent()
                .putExtra(WidgetActionActivity.EXTRA_ACTION, WidgetActionActivity.ACTION_TOGGLE)
                .putExtra(TasksWidgetProvider.EXTRA_TASK_ID, task.id)
                .putExtra(TasksWidgetProvider.EXTRA_DONE, !task.done));
            view.setOnClickFillInIntent(R.id.task_body, new Intent()
                .putExtra(WidgetActionActivity.EXTRA_ACTION, WidgetActionActivity.ACTION_OPEN)
                .putExtra(TasksWidgetProvider.EXTRA_TASK_ID, task.id));
            return view;
        }

        private String shortDate(String isoDate) {
            try {
                Date date = new SimpleDateFormat("yyyy-MM-dd", Locale.US).parse(isoDate);
                return new SimpleDateFormat("d MMM", Locale.getDefault()).format(date);
            } catch (ParseException exception) {
                return isoDate;
            }
        }

        @Override
        public RemoteViews getLoadingView() {
            return null;
        }

        @Override
        public int getViewTypeCount() {
            return 2;
        }

        @Override
        public long getItemId(int position) {
            if (position < 0 || position >= rows.size()) return position;
            WidgetTree.Row row = rows.get(position);
            return row.task == null ? -1L : row.task.id;
        }

        @Override
        public boolean hasStableIds() {
            return true;
        }
    }
}
