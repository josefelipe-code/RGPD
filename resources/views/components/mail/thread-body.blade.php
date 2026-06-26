{{--
    Mail Thread Body
    Scrollable reading surface for the message body content.

    Props:
    - body (HtmlString) — sanitized HTML body content
--}}
@props(['body'])

<div class="min-h-0 flex-1 overflow-y-auto">
    <div class="px-5 py-4">
        <div class="prose prose-sm max-w-none break-words dark:prose-invert
            prose-headings:font-semibold prose-headings:text-zinc-900 dark:prose-headings:text-zinc-100
            prose-p:text-zinc-700 dark:prose-p:text-zinc-300
            prose-a:text-blue-600 dark:prose-a:text-blue-400
            prose-strong:text-zinc-900 dark:prose-strong:text-zinc-100
            prose-li:text-zinc-700 dark:prose-li:text-zinc-300
            prose-blockquote:border-l-zinc-300 dark:prose-blockquote:border-l-zinc-600
            prose-blockquote:text-zinc-500 dark:prose-blockquote:text-zinc-400
            prose-hr:border-zinc-200 dark:prose-hr:border-zinc-700
            prose-table:border-collapse prose-td:border prose-td:border-zinc-200 prose-td:px-2 prose-td:py-1 prose-td:text-sm dark:prose-td:border-zinc-700
            prose-th:border prose-th:border-zinc-200 prose-th:px-2 prose-th:py-1 prose-th:text-sm prose-th:font-semibold dark:prose-th:border-zinc-700
            prose-img:rounded-lg prose-img:border prose-img:border-zinc-200 dark:prose-img:border-zinc-700">
            {!! $body !!}
        </div>
    </div>
</div>
