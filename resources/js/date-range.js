// Calendar filter field: a double-click picks one day, two separate dates pick a range in either order.
import { isIso, monthCells, monthName, toDisplay, todayIso } from './calendar';

const RANGE_CLOSE_DELAY = 300;

window.nexoraDateRange = (wire, fromModel, untilModel, live = false) => ({
    from: wire.entangle(fromModel, live),
    until: wire.entangle(untilModel, live),
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
        return todayIso();
    },

    get summary() {
        const from = isIso(this.from) ? this.from : '';
        const until = isIso(this.until) ? this.until : '';
        if (from && until) return from === until ? toDisplay(from) : `${toDisplay(from)} – ${toDisplay(until)}`;
        if (from) return `From ${toDisplay(from)}`;
        return until ? `Until ${toDisplay(until)}` : '';
    },

    get monthLabel() {
        return monthName(this.viewYear, this.viewMonth);
    },

    get cells() {
        return monthCells(this.viewYear, this.viewMonth);
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
