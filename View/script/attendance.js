document.addEventListener('DOMContentLoaded', () => {
  const att = document.getElementById('att');
  const backBtn = att && att.querySelector('.back-btn');
  if (!backBtn) return;

  const header = document.querySelector('header');
  const title = header && [...header.querySelectorAll('*')].find(el => !el.children.length && /^\s*ATTENDANCE/i.test(el.textContent));

  if (title) {
    const wrap = document.createElement('span');
    wrap.className = 'att-back-wrap';
    const link = document.createElement('a');
    link.className = 'att-back';
    link.href = backBtn.getAttribute('href');
    link.title = backBtn.title;
    link.setAttribute('aria-label', backBtn.title);
    link.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>';
    title.parentNode.insertBefore(wrap, title);
    wrap.append(link, title);
    backBtn.remove();
  }

  const del = att.querySelector('.icon-btn.del');
  if (!del) return;
  att.classList.add('sel-hidden');
  del.addEventListener('click', e => {
    if (att.classList.contains('sel-hidden')) {
      e.preventDefault();
      att.classList.remove('sel-hidden');
      return;
    }
    const checked = att.querySelectorAll('.rd:checked').length;
    if (!checked) {
      e.preventDefault();
      att.classList.add('sel-hidden');
      return;
    }
    if (!confirm(`Delete ${checked} selected record${checked === 1 ? '' : 's'}?`)) e.preventDefault();
  });
});
