// Calendar filter field: a double-click picks one day, two separate dates pick a range in either order.
const RANGE_CLOSE_DELAY = 300;
const pad = value => String(value).padStart(2, '0');
const toIso = (year, month, day) => `${year}-${pad(month + 1)}-${pad(day)}`;
const isIso = value => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '');
const toDisplay = iso => iso.split('-').reverse().join('/');

window.nexoraDateRange = (wire, fromModel, untilModel) => ({
    from: wire.entangle(fromModel),
    until: wire.entangle(untilModel),
    open: false,
    pending: null,
    hovered: null,
    viewYear: 0,
    viewMonth: 0,
    closeTimer: null,

    init() {
        this.showMonthOf(this.from || this.until);
    },

    get today() {
        const now = new Date();
        return toIso(now.getFullYear(), now.getMonth(), now.getDate());
    },

    get summary() {
        const from = isIso(this.from) ? this.from : '';
        const until = isIso(this.until) ? this.until : '';
        if (from && until) return from === until ? toDisplay(from) : `${toDisplay(from)} – ${toDisplay(until)}`;
        if (from) return `From ${toDisplay(from)}`;
        return until ? `Until ${toDisplay(until)}` : '';
    },

    get monthLabel() {
        return new Date(this.viewYear, this.viewMonth, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    },

    get cells() {
        const leadingBlanks = (new Date(this.viewYear, this.viewMonth, 1).getDay() + 6) % 7;
        const dayCount = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
        const blanks = Array.from({ length: leadingBlanks }, (_, index) => ({ key: `blank-${index}`, iso: null }));
        const days = Array.from({ length: dayCount }, (_, index) => {
            const day = index + 1;
            const iso = toIso(this.viewYear, this.viewMonth, day);
            return { key: iso, iso, day, label: new Date(this.viewYear, this.viewMonth, day).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) };
        });
        return [...blanks, ...days];
    },

    get hint() {
        return this.pending ? `Start ${toDisplay(this.pending)}. Now choose the end date.` : 'Double-click one date, or choose a start and end date.';
    },

    showMonthOf(iso) {
        const [year, month] = (isIso(iso) ? iso : this.today).split('-').map(Number);
        this.viewYear = year;
        this.viewMonth = month - 1;
    },

    shiftMonth(step) {
        const target = new Date(this.viewYear, this.viewMonth + step, 1);
        this.viewYear = target.getFullYear();
        this.viewMonth = target.getMonth();
    },

    toggle() {
        if (this.open) {
            this.close();
            return;
        }
        this.showMonthOf(this.from || this.until);
        this.open = true;
    },

    close() {
        clearTimeout(this.closeTimer);
        this.open = false;
        this.pending = null;
        this.hovered = null;
    },

    choose(iso) {
        clearTimeout(this.closeTimer);
        if (!this.pending) {
            this.pending = iso;
            return;
        }
        [this.from, this.until] = [this.pending, iso].sort();
        this.pending = null;
        this.hovered = null;
        // Stay open briefly so the second click of a double-click cannot land on the page underneath.
        this.closeTimer = setTimeout(() => this.finish(), RANGE_CLOSE_DELAY);
    },

    chooseSingleDay(iso) {
        this.from = iso;
        this.until = iso;
        this.finish();
    },

    clear() {
        this.from = '';
        this.until = '';
        this.finish();
    },

    finish() {
        this.close();
        this.$refs.trigger.focus();
    },

    stateOf(iso) {
        const [start, end] = this.pending ? [this.pending, this.hovered ?? this.pending].sort() : [this.from, this.until];
        if (iso === start || iso === end) return 'selected';
        return start && end && iso > start && iso < end ? 'range' : '';
    },
});
