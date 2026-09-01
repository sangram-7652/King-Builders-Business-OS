{{--
    Global toast host. Render once (already included in the app layout).

    Fire from Livewire:  $this->dispatch('toast', message: 'Saved', variant: 'success');
    Fire from Alpine:    $dispatch('toast', { message: 'Saved', variant: 'success' })
--}}
<div
    x-data="{
        toasts: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: detail.message ?? '', variant: detail.variant ?? 'info' });
            setTimeout(() => this.remove(id), detail.timeout ?? 4000);
        },
        remove(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
    }"
    @toast.window="push($event.detail)"
    class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-full max-w-sm flex-col gap-2"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition
            class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-(--surface) px-4 py-3 text-sm shadow-lg"
            :class="{
                'border-emerald-500/30 text-emerald-800': toast.variant === 'success',
                'border-red-500/30 text-red-800': toast.variant === 'danger',
                'border-amber-500/30 text-amber-800': toast.variant === 'warning',
                'border-sky-500/30 text-sky-800': toast.variant === 'info',
            }"
        >
            <span class="flex-1" x-text="toast.message"></span>
            <button type="button" class="opacity-60 hover:opacity-100" @click="remove(toast.id)" aria-label="Dismiss">&times;</button>
        </div>
    </template>
</div>
