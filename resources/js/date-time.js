// Calendar field for one date and time, stored as the Y-m-dTH:i value datetime-local inputs produce.
import { isIso, monthCells, monthName, toDisplay, todayIso } from './calendar';

window.nexoraDateTime = (wire, model, defaultTime, minimumDate = null) => ({
    value: wire.entangle(model),
    open: false,
    viewYear: 0,
    viewMonth: 0,

    init() {
        this.showMonthOf(this.date);
    },

    get today() {
        return todayIso();
    },

    get date() {
        const date = (this.value ?? '').split('T')[0];
        return isIso(date) ? date : '';
    },

    get time() {
        return this.date ? (this.value.split('T')[1] ?? '') : '';
    },

    get summary() {
        if (!this.date) return '';
        return this.time ? `${toDisplay(this.date)}, ${this.time}` : toDisplay(this.date);
    },

    get monthLabel() {
        return monthName(this.viewYear, this.viewMonth);
    },

    get cells() {
        return monthCells(this.viewYear, this.viewMonth);
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
            this.open = false;
            return;
        }
        this.showMonthOf(this.date);
        this.open = true;
    },

    isBeforeMinimum(iso) {
        return minimumDate !== null && iso < minimumDate;
    },

    choose(iso) {
        this.value = defaultTime === null ? iso : `${iso}T${this.time || defaultTime}`;
    },

    setTime(time) {
        if (this.date && time) {
            this.value = `${this.date}T${time}`;
        }
    },

    clear() {
        this.value = '';
        this.finish();
    },

    finish() {
        this.open = false;
        this.$refs.trigger.focus();
    },
});
