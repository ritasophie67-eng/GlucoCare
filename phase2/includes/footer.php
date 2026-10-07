<?php if (($layout ?? 'app') === 'auth'): ?>
        </div>
    </main>
</div>
<?php else: ?>
        </main>
        <footer class="app-footer">
            <?= e(APP_NAME) ?> · <?= e(HOSPITAL_NAME) ?> · Clinical decision-support prototype — supports, never replaces, clinical judgement.
        </footer>
    </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?= $page_scripts ?? '' ?>
</body>
</html>
