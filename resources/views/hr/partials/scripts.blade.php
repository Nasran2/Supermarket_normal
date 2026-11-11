@push('scripts')
<script>
document.querySelector('[data-hr-mark-present]')?.addEventListener('click', () => {
    document.querySelectorAll('[data-hr-attendance-row] select:enabled').forEach(select => {
        if (!select.value) select.value = 'PRESENT';
    });
});

document.querySelectorAll('[data-hr-attendance-row] select').forEach(select => {
    select.addEventListener('change', () => {
        if (['PRESENT', 'LATE', 'HALF_DAY'].includes(select.value)) return;

        const row = select.closest('tr');
        row.querySelectorAll('input[type=time]').forEach(input => input.value = '');
        row.querySelector('input[type=number]').value = '0';
        row.querySelector('input[type=checkbox]').checked = false;
    });
});

const hrLines = document.querySelector('[data-hr-lines]');
const lineTemplate = document.querySelector('[data-hr-line-template]');
const addLine = document.querySelector('[data-hr-add-line]');

if (hrLines && lineTemplate && addLine) {
    const currentIndices = Array.from(hrLines.querySelectorAll('select[name]'), select =>
        Number(select.name.match(/\[(\d+)\]/)?.[1] ?? -1));
    let nextLine = Math.max(-1, ...currentIndices) + 1;

    const updateAddButton = () => {
        addLine.disabled = hrLines.children.length >= 30;
    };

    addLine.addEventListener('click', () => {
        const line = lineTemplate.content.firstElementChild.cloneNode(true);
        line.querySelectorAll('[name]').forEach(input => input.name = input.name.replace('__INDEX__', nextLine));
        nextLine++;
        hrLines.append(line);
        line.querySelector('select').focus();
        updateAddButton();
    });

    hrLines.addEventListener('click', event => {
        const line = event.target.closest('[data-hr-remove-line]')?.closest('[data-hr-payroll-line]');
        if (!line) return;

        line.remove();
        updateAddButton();
        addLine.focus();
    });

    updateAddButton();
}
</script>
@endpush
