<template>
  <div class="app-shell">
    <aside class="sidebar">
      <RouterLink class="brand" to="/">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </RouterLink>
      <nav class="nav-list" aria-label="Primary navigation">
        <RouterLink to="/">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Today</span>
        </RouterLink>
        <RouterLink to="/routines">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Routines</span>
        </RouterLink>
        <RouterLink to="/goals">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Goals</span>
        </RouterLink>
        <RouterLink to="/review">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Review</span>
        </RouterLink>
      </nav>

      <div class="user-pill">
        <span>A</span>
        <div>
          <strong>Alex</strong>
          <small>single-user</small>
        </div>
      </div>
    </aside>

    <main class="content-shell">
      <RouterView />
    </main>
  </div>
</template>
