export function inputClasses(error) {
    return [
        'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400',
        'focus:outline-none focus:ring-2 disabled:bg-slate-50 disabled:text-slate-500',
        error
            ? 'border-red-300 focus:border-red-400 focus:ring-red-200'
            : 'border-slate-300 focus:border-brand-500 focus:ring-brand-200',
    ];
}
