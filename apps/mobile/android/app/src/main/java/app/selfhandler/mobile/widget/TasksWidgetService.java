package app.selfhandler.mobile.widget;

import android.content.Context;
import android.content.Intent;
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

/** Supplies widget rows from the cached list; network work happens in TasksWidgetProvider. */
public class TasksWidgetService extends RemoteViewsService {
    @Override
    public RemoteViewsFactory onGetViewFactory(Intent intent) {
        return new Factory(getApplicationContext());
    }

    private static final class Factory implements RemoteViewsFactory {
        private final Context context;
        private List<WidgetTask> tasks = new ArrayList<>();
        private String today = TasksWidgetProvider.today();

        Factory(Context context) {
            this.context = context;
        }

        @Override
        public void onCreate() {
        }

        @Override
        public void onDataSetChanged() {
            today = TasksWidgetProvider.today();
            tasks = TaskWidgetStore.visibleTasks(context);
        }

        @Override
        public void onDestroy() {
            tasks.clear();
        }

        @Override
        public int getCount() {
            return tasks.size();
        }

        @Override
        public RemoteViews getViewAt(int position) {
            RemoteViews row = new RemoteViews(context.getPackageName(), R.layout.widget_task_item);
            if (position < 0 || position >= tasks.size()) return row;
            WidgetTask task = tasks.get(position);

            row.setTextViewText(R.id.task_title, task.title);
            row.setViewVisibility(R.id.task_priority, task.isHigh() ? View.VISIBLE : View.GONE);
            boolean overdue = task.dueOn != null && task.dueOn.compareTo(today) < 0;
            row.setViewVisibility(R.id.task_due, overdue ? View.VISIBLE : View.GONE);
            if (overdue) row.setTextViewText(R.id.task_due, context.getString(R.string.widget_overdue, shortDate(task.dueOn)));
            row.setContentDescription(R.id.task_check, context.getString(R.string.widget_mark_done, task.title));

            row.setOnClickFillInIntent(R.id.task_check, new Intent()
                .putExtra(WidgetActionActivity.EXTRA_ACTION, WidgetActionActivity.ACTION_COMPLETE)
                .putExtra(TasksWidgetProvider.EXTRA_TASK_ID, task.id));
            row.setOnClickFillInIntent(R.id.task_body, new Intent()
                .putExtra(WidgetActionActivity.EXTRA_ACTION, WidgetActionActivity.ACTION_OPEN)
                .putExtra(TasksWidgetProvider.EXTRA_TASK_ID, task.id));
            return row;
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
            return 1;
        }

        @Override
        public long getItemId(int position) {
            return position >= 0 && position < tasks.size() ? tasks.get(position).id : position;
        }

        @Override
        public boolean hasStableIds() {
            return true;
        }
    }
}
