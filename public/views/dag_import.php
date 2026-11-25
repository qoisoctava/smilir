<?php
/** @var ?array $import  ['path','dag','warnings','source'] */
?>
<header class="page-head">
  <div>
    <p class="muted small"><a class="link" href="<?= url('/dags') ?>">← Dags</a></p>
    <h1>Import Dag definition</h1>
    <p class="muted small">Upload a Python DAG module — <?= e(brand_name()) ?> loads it in the default venv,
      checks the cron and dependencies, then adds it to <span class="mono">dags/</span>.</p>
  </div>
</header>

<?php if (!$import): ?>
  <section class="card">
    <div class="card-head"><h3>Upload</h3><span class="muted small">.py · max 256 KB</span></div>
    <form method="post" enctype="multipart/form-data" action="<?= url('/dags/import') ?>"
          class="dropzone">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <span class="glyph">⤒</span>
      <p><strong>Drop or choose a .py file</strong></p>
      <p class="muted small">Legacy <span class="mono">.yaml</span>/<span class="mono">.php</span> import is not enabled in this build.</p>
      <p><input type="file" name="file" accept=".py" required>
         <button class="btn btn-primary" type="submit">Parse &amp; preview</button></p>
    </form>
    <p class="muted small">Prefer writing it by hand? <a class="link" href="<?= url('/dags/new') ?>">Open the editor</a>.</p>
  </section>
<?php else: ?>
  <?php $dag = $import['dag']; ?>
  <section class="card">
    <div class="card-head">
      <h3>Parsed preview</h3>
      <span class="muted small push"><?= e($import['source']) ?></span>
    </div>
    <div class="kv-row">
      <div><span class="label">Name</span><span class="mono"><?= e($dag['name']) ?></span></div>
      <div><span class="label">Schedule</span><span class="mono">
        <?= e(($dag['schedule_type'] ?? 'cron') === 'interval' ? 'interval:' . $dag['schedule'] : $dag['schedule']) ?></span></div>
      <div><span class="label">Tasks</span><span class="mono"><?= count($dag['tasks'] ?? []) ?></span></div>
      <div><span class="label">Venv</span><span class="mono"><?= e((string) ($dag['venv'] ?? '—')) ?></span></div>
    </div>
    <?php if (!empty($import['warnings'])): ?>
      <ul class="warnings">
        <?php foreach ($import['warnings'] as $w): ?><li><?= e((string) $w) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <table class="table">
      <thead><tr><th>Task</th><th>Func / Command</th><th>Depends on</th><th>Retries</th><th>Timeout</th></tr></thead>
      <tbody>
      <?php foreach (($dag['tasks'] ?? []) as $t): ?>
        <tr>
          <td class="mono"><?= e((string) ($t['name'] ?? '')) ?></td>
          <td class="mono small"><?= e((string) ($t['func'] ?? $t['command'] ?? '')) ?></td>
          <td class="muted small"><?= e(implode(', ', (array) ($t['depends_on'] ?? []))) ?></td>
          <td class="mono small"><?= (int) ($t['retries'] ?? 0) ?></td>
          <td class="mono small"><?= (int) ($t['timeout'] ?? 0) ?: 'none' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <div class="toolbar">
    <form class="inline" method="post" action="<?= url('/dags/import/confirm') ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-primary" type="submit">Import Dag</button>
    </form>
    <a class="btn" href="<?= url('/dags/import?cancel=1') ?>">Cancel</a>
  </div>
<?php endif; ?>
