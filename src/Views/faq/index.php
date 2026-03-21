<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$quickLinks = $quickLinks ?? [];
$processFlow = $processFlow ?? [];
$faqGroups = $faqGroups ?? [];
?>
<section class="space-y-8">
    <div class="overflow-hidden rounded-[32px] border border-white/70 bg-white/90 shadow-card backdrop-blur">
        <div class="grid gap-8 px-6 py-7 sm:px-8 lg:grid-cols-[1.25fr_0.75fr] lg:px-10 lg:py-10">
            <div>
                <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-700">FAQ operacional</div>
                <h1 class="mt-3 max-w-3xl font-display text-3xl font-semibold leading-tight text-ink-950 sm:text-4xl">
                    Todos os processos do TechRecruit em uma única página de consulta.
                </h1>
                <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-600 sm:text-base">
                    Esta FAQ resume o fluxo completo do sistema, do primeiro acesso até a decisão operacional do candidato.
                    A ideia é reduzir dúvida de uso na rotina e deixar claro o papel de cada tela, automação e integração.
                </p>

                <div class="mt-6 flex flex-wrap gap-3">
                    <?php foreach ($quickLinks as $link): ?>
                        <a
                            href="<?= $escape($link['href'] ?? '#') ?>"
                            class="inline-flex min-h-[44px] items-center rounded-full border border-brand-200 bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-800 transition hover:bg-brand-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                        >
                            <?= $escape($link['label'] ?? '') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="rounded-[28px] border border-slate-200 bg-slate-50/90 p-5 shadow-sm">
                <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Atalhos do processo</div>
                <div class="mt-4 space-y-3">
                    <?php foreach ($quickLinks as $link): ?>
                        <a
                            href="<?= $escape($link['href'] ?? '#') ?>"
                            class="block rounded-2xl border border-transparent bg-white px-4 py-4 transition hover:border-slate-200 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                        >
                            <div class="text-sm font-semibold text-ink-950"><?= $escape($link['label'] ?? '') ?></div>
                            <div class="mt-1 text-sm leading-6 text-slate-500"><?= $escape($link['description'] ?? '') ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-[0.82fr_1.18fr]">
        <aside class="space-y-6 lg:sticky lg:top-8 lg:self-start">
            <div class="rounded-[28px] border border-white/70 bg-white/90 p-5 shadow-sm backdrop-blur">
                <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Índice da FAQ</div>
                <div class="mt-4 space-y-2">
                    <?php foreach ($faqGroups as $group): ?>
                        <a
                            href="#<?= $escape($group['id'] ?? '') ?>"
                            class="flex min-h-[44px] items-center rounded-2xl border border-transparent px-4 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-200 hover:bg-slate-50 hover:text-ink-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                        >
                            <?= $escape($group['title'] ?? '') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/70 bg-white/90 p-5 shadow-sm backdrop-blur">
                <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Mapa do fluxo</div>
                <div class="mt-5 space-y-4">
                    <?php foreach ($processFlow as $item): ?>
                        <a
                            href="<?= $escape($item['route'] ?? '#') ?>"
                            class="flex gap-4 rounded-[24px] border border-slate-200 bg-slate-50/90 px-4 py-4 transition hover:border-brand-200 hover:bg-white hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                        >
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-ink-950 text-xs font-semibold tracking-[0.2em] text-white">
                                <?= $escape($item['step'] ?? '') ?>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-ink-950"><?= $escape($item['title'] ?? '') ?></span>
                                <span class="mt-1 block text-sm leading-6 text-slate-500"><?= $escape($item['description'] ?? '') ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="rounded-[28px] border border-brand-100 bg-gradient-to-br from-brand-50 via-white to-accent-50 p-5 shadow-sm">
                <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-700">Ordem recomendada</div>
                <ol class="mt-4 space-y-3 text-sm leading-6 text-slate-700">
                    <li><span class="font-semibold text-ink-950">1.</span> Crie o acesso interno e valide roles.</li>
                    <li><span class="font-semibold text-ink-950">2.</span> Importe a base e confira filtros em candidatos.</li>
                    <li><span class="font-semibold text-ink-950">3.</span> Dispare campanhas ou abra sessões W13.</li>
                    <li><span class="font-semibold text-ink-950">4.</span> Gere o portal para quem avançar.</li>
                    <li><span class="font-semibold text-ink-950">5.</span> Finalize a análise em operações.</li>
                </ol>
            </div>
        </aside>

        <div class="space-y-6">
            <?php foreach ($faqGroups as $group): ?>
                <section id="<?= $escape($group['id'] ?? '') ?>" class="rounded-[30px] border border-white/70 bg-white/92 p-6 shadow-card backdrop-blur sm:p-7">
                    <div class="max-w-3xl">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">
                            <?= $escape($group['eyebrow'] ?? '') ?>
                        </div>
                        <h2 class="mt-2 font-display text-2xl font-semibold text-ink-950">
                            <?= $escape($group['title'] ?? '') ?>
                        </h2>
                        <p class="mt-3 text-sm leading-7 text-slate-600">
                            <?= $escape($group['summary'] ?? '') ?>
                        </p>
                    </div>

                    <div class="mt-6 space-y-3">
                        <?php foreach (($group['items'] ?? []) as $index => $item): ?>
                            <details class="group rounded-[24px] border border-slate-200 bg-slate-50/80 px-5 py-4 transition open:border-brand-200 open:bg-white open:shadow-sm" <?= $index === 0 ? 'open' : '' ?>>
                                <summary class="flex min-h-[44px] cursor-pointer list-none items-center justify-between gap-4 text-left focus-visible:outline-none">
                                    <span class="text-sm font-semibold leading-6 text-ink-950">
                                        <?= $escape($item['question'] ?? '') ?>
                                    </span>
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition group-open:rotate-45 group-open:border-brand-200 group-open:text-brand-700">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="h-4 w-4">
                                            <path d="M12 5v14"></path>
                                            <path d="M5 12h14"></path>
                                        </svg>
                                    </span>
                                </summary>
                                <div class="pt-4 text-sm leading-7 text-slate-600">
                                    <?= $escape($item['answer'] ?? '') ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</section>
