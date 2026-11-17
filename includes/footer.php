<?php if (($layout ?? 'app') === 'auth'): ?>
            </div>
            <p class="mt-6 text-center text-[0.7rem] leading-relaxed text-muted-foreground">
                Clinical decision-support prototype. It supports — never replaces — clinical judgement.
            </p>
        </div>
    </main>
</div>
<?php else: ?>
        </main>
        <footer class="border-t border-border bg-card/60 px-4 py-4 sm:px-6 lg:px-8">
            <div class="mx-auto flex max-w-[1400px] flex-col gap-1 text-[0.72rem] text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                <p><?= e(APP_NAME) ?> · <?= e(PLATFORM_TAGLINE) ?></p>
                <p>Decision support only — clinical judgement remains with the care team.</p>
            </div>
        </footer>
    </div>
</div>
<?php endif; ?>

<div id="toast-region" aria-live="polite" aria-atomic="false"
     class="pointer-events-none fixed inset-x-3 top-3 z-[70] flex flex-col items-end gap-2 sm:inset-x-auto sm:right-5 sm:top-5 sm:w-full sm:max-w-sm">
    <?php if ($flash): ?>
        <?php
        $toast_tone = match ($flash['type']) {
            'success' => 'border-success/30 bg-success-subtle text-success',
            'warning' => 'border-warning/30 bg-warning-subtle text-warning',
            'danger', 'destructive' => 'border-destructive/30 bg-destructive-subtle text-destructive',
            default   => 'border-primary/30 bg-primary-subtle text-primary',
        };
        $toast_icon = match ($flash['type']) {
            'success' => 'check-circle',
            'warning' => 'alert-triangle',
            'danger', 'destructive' => 'alert-octagon',
            default   => 'info',
        };
        ?>
        <div data-toast class="pointer-events-auto flex w-full max-w-sm animate-toast-in items-start gap-2.5 rounded-lg border px-4 py-3 text-sm shadow-lifted <?= $toast_tone ?>">
            <span class="mt-0.5 shrink-0"><?= icon($toast_icon, 'h-4 w-4') ?></span>
            <p class="flex-1 leading-relaxed"><?= e($flash['message']) ?></p>
            <button type="button" data-toast-close class="-mr-1 shrink-0 rounded p-0.5 opacity-70 transition hover:opacity-100" aria-label="Dismiss notification">
                <?= icon('x', 'h-3.5 w-3.5') ?>
            </button>
        </div>
    <?php endif; ?>
</div>

<script src="<?= BASE_URL ?>assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<?= $page_scripts ?? '' ?>
</body>
</html>
