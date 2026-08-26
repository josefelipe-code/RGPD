<div
    x-data="{
        now: new Date({{ now(config('app.timezone'))->getTimestamp() * 1000 }}),
        locale: @js(app()->getLocale()),
        timeZone: @js(config('app.timezone')),
        timer: null,
        init() {
            this.timer = window.setInterval(() => {
                this.now = new Date(this.now.getTime() + 1000)
            }, 1000)
        },
        destroy() {
            window.clearInterval(this.timer)
        },
    }"
    class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300"
    aria-live="off"
    data-test="topbar-clock"
>
    <flux:icon icon="calendar-days" class="size-4" aria-hidden="true" />
    <time x-text="new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone }).format(now)"></time>
    <time x-text="new Intl.DateTimeFormat(locale, { timeStyle: 'short', timeZone }).format(now)"></time>
</div>
