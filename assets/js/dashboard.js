const csrfToken = () => {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
};

const apiUrl = (path) => `/api/index.php${path.startsWith('/') ? path : `/${path}`}`;

async function apiFetch(path, options = {}) {
  const headers = {
    Accept: 'application/json',
    'X-CSRF-Token': csrfToken(),
    ...options.headers,
  };

  if (options.body && !(options.headers && options.headers['Content-Type'])) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(apiUrl(path), {
    credentials: 'same-origin',
    ...options,
    headers,
  });

  let data = null;
  try {
    data = await response.json();
  } catch (err) {
    data = null;
  }

  if (!response.ok) {
    const error = new Error((data && data.error) || 'Request failed');
    error.response = response;
    error.payload = data;
    throw error;
  }

  return data;
}

function formatRelative(dateString) {
  if (!dateString) return '';
  const diff = (Date.now() - new Date(dateString).getTime()) / 1000;
  const abs = Math.abs(diff);
  const units = [
    { label: 'second', secs: 60 },
    { label: 'minute', secs: 60 },
    { label: 'hour', secs: 24 },
    { label: 'day', secs: 7 },
    { label: 'week', secs: 4.34524 },
    { label: 'month', secs: 12 },
    { label: 'year', secs: Infinity },
  ];
  let value = abs;
  let unit = 'second';
  for (const entry of units) {
    if (value < entry.secs) {
      unit = entry.label;
      break;
    }
    value /= entry.secs;
  }
  const rounded = Math.max(1, Math.round(value));
  const label = rounded === 1 ? unit : `${unit}s`;
  return diff > 0 ? `${rounded} ${label} ago` : `in ${rounded} ${label}`;
}

function renderRecent(list, tasks) {
  if (!list) return;
  list.innerHTML = '';

  if (!tasks || !tasks.length) {
    const empty = document.createElement('li');
    empty.className = 'recent-empty';
    empty.textContent = 'No recent updates yet.';
    list.appendChild(empty);
    return;
  }

  tasks.forEach((task) => {
    const item = document.createElement('li');
    item.className = 'recent-item';
    item.dataset.taskId = String(task.id);
    item.dataset.status = task.status;

    const header = document.createElement('div');
    header.className = 'recent-row';

    const link = document.createElement('a');
    link.className = 'recent-title';
    link.href = `/task_view.php?id=${task.id}`;
    link.textContent = task.title;

    const pills = document.createElement('div');
    pills.className = 'recent-pills';

    const statusPill = document.createElement('span');
    statusPill.className = `recent-pill recent-pill--status-${task.status}`;
    statusPill.textContent = task.status.replace(/_/g, ' ');
    pills.appendChild(statusPill);

    if (task.priority) {
      const priorityPill = document.createElement('span');
      priorityPill.className = 'recent-pill recent-pill--priority';
      priorityPill.textContent = String(task.priority).toUpperCase();
      pills.appendChild(priorityPill);
    }

    const meta = document.createElement('div');
    meta.className = 'recent-meta';
    const metaValues = [
      task.building_name || 'Building',
      task.room_label || '',
      task.due_date ? `Due ${task.due_date}` : 'No due date',
      formatRelative(task.updated_at),
    ];
    metaValues.forEach((value) => {
      if (!value) return;
      const span = document.createElement('span');
      span.textContent = value;
      meta.appendChild(span);
    });

    const action = document.createElement('button');
    action.type = 'button';
    action.className = 'recent-action';
    action.dataset.action = 'complete-task';
    action.dataset.taskId = String(task.id);
    action.textContent = task.status === 'done' ? 'Done' : 'Mark done';
    action.disabled = task.status === 'done';

    header.appendChild(link);
    header.appendChild(pills);
    item.appendChild(header);
    item.appendChild(meta);
    item.appendChild(action);

    list.appendChild(item);
  });
}

function initDashboard() {
  const quickForm = document.getElementById('quickTaskForm');
  const feedback = quickForm ? quickForm.querySelector('[data-quick-feedback]') : null;
  const submitBtn = quickForm ? quickForm.querySelector('[data-quick-submit]') : null;
  const recentList = document.querySelector('[data-recent-list]');
  const refreshButton = document.querySelector('[data-refresh-recent]');

  const setFeedback = (message, type = 'info') => {
    if (!feedback) return;
    feedback.textContent = message || '';
    feedback.classList.remove('is-error', 'is-success');
    if (type === 'error') feedback.classList.add('is-error');
    if (type === 'success') feedback.classList.add('is-success');
  };

  const loadRecent = async () => {
    if (!recentList) return;
    const previousMarkup = recentList.innerHTML;
    recentList.classList.add('is-loading');
    try {
      const data = await apiFetch('/v2/tasks?sort=updated&limit=6');
      renderRecent(recentList, data.data || []);
    } catch (error) {
      recentList.innerHTML = previousMarkup;
    } finally {
      recentList.classList.remove('is-loading');
    }
  };

  if (refreshButton) {
    refreshButton.addEventListener('click', (event) => {
      event.preventDefault();
      loadRecent();
    });
  }

  if (quickForm) {
    quickForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!quickForm.reportValidity()) return;
      const formData = new FormData(quickForm);
      const payload = Object.fromEntries(formData.entries());
      payload.building_id = Number(payload.building_id || 0);
      payload.room_id = Number(payload.room_id || 0);
      if (!payload.title || !payload.building_id || !payload.room_id) {
        setFeedback('Title, building, and room are required.', 'error');
        return;
      }
      try {
        submitBtn && (submitBtn.disabled = true);
        setFeedback('Creating task…');
        await apiFetch('/v2/tasks', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        quickForm.reset();
        const roomSelect = document.getElementById('quick-room');
        if (roomSelect) {
          roomSelect.innerHTML = '<option value="">Select room</option>';
        }
        setFeedback('Task created successfully.', 'success');
        loadRecent();
      } catch (error) {
        setFeedback(error.message || 'Unable to create task.', 'error');
      } finally {
        submitBtn && (submitBtn.disabled = false);
      }
    });
  }

  if (recentList) {
    recentList.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-action="complete-task"]');
      if (!button) return;
      const taskId = Number(button.dataset.taskId || 0);
      if (!taskId || button.disabled) return;
      button.disabled = true;
      button.textContent = 'Updating…';
      try {
        await apiFetch(`/v2/tasks/${taskId}`, {
          method: 'PATCH',
          body: JSON.stringify({ status: 'done' }),
        });
        button.textContent = 'Done';
        loadRecent();
      } catch (error) {
        button.disabled = false;
        button.textContent = 'Mark done';
      }
    });
  }

  loadRecent();
}

document.addEventListener('DOMContentLoaded', initDashboard);