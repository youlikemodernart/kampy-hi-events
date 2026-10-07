'use strict';
(() => {
    let token = location.hash.slice(1);
    history.replaceState(null, '', location.pathname);
    const form = document.querySelector('#choices');
    const status = document.querySelector('#status');
    let siblings = [];
    async function post(body) {
        const response = await fetch(location.pathname, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-Kamp-Respondent-Intent': 'confirm'}, body: JSON.stringify(body)});
        if (!response.ok) throw new Error('unavailable');
        return response.json();
    }
    function field(label, input) {
        const wrapper = document.createElement('label'); wrapper.className = 'field'; wrapper.append(document.createTextNode(label), input); return wrapper;
    }
    function render(result) {
        siblings = result.siblings;
        form.hidden = result.status !== 'choosing';
        document.querySelector('#ready').hidden = result.status !== 'confirmed';
        status.textContent = result.status === 'confirmed' ? (result.pending ? 'Your choices are saved. We’re preparing each waiver — choose “Refresh waiver status” to continue. You don’t need another email.' : 'Your choices are saved. Continue below. A waiver is complete only after it is signed and submitted.') : 'Who will sign for each attendee?';
        if (result.status === 'choosing') {
            document.querySelector('#siblings').replaceChildren(...siblings.map(attendee => {
                const group = document.createElement('fieldset'); group.dataset.id = attendee.id; group.className = 'section';
                const legend = document.createElement('legend'); legend.textContent = `${attendee.first_name} ${attendee.last_name}`; legend.className = 'sectionLegend'; group.append(legend);
                const route = document.createElement('select'); route.className = 'select'; route.name = 'route'; route.required = true;
                [['', 'Choose a signer'], ['adult', 'Adult attendee — self'], ['guardian', 'Parent or legal guardian']].forEach(([value, label]) => route.add(new Option(label, value)));
                const name = document.createElement('input'); name.className = 'input'; name.name = 'respondent_name'; name.maxLength = 160;
                const nameField = field('Parent or legal guardian’s full name', name); nameField.hidden = true;
                route.onchange = () => {nameField.hidden = route.value !== 'guardian'; name.required = route.value === 'guardian';};
                const email = document.createElement('input'); email.className = 'input'; email.name = 'email'; email.type = 'email'; email.maxLength = 320; email.required = true;
                group.append(field('Signer', route), nameField, field('Signer’s email', email)); return group;
            }));
        }
        const links = document.querySelector('#links'); links.replaceChildren();
        for (const link of result.links ?? []) {
            const a = document.createElement('a'); a.className = 'btn btnPrimary'; a.rel = 'noreferrer';
            // Server validates the fixed Portal origin; never render arbitrary protocols.
            const url = new URL(link.url); if (url.protocol !== 'https:' && url.hostname !== '127.0.0.1') continue;
            a.href = url.href; a.textContent = `${link.attendee_name} — ${link.complete ? 'View completed waiver' : 'Complete waiver'}`; links.append(a);
        }
    }
    function unavailable() {status.textContent = 'This link is unavailable or has expired. Open the email we sent you again, or contact Kamp Love for help. Opening this page hasn’t signed anything.';}
    async function resume() {try {render(await post({action: 'resume'}));} catch {unavailable();}}
    form.onsubmit = async event => {
        event.preventDefault(); const button = form.querySelector('button'); button.disabled = true;
        const respondents = [...form.querySelectorAll('fieldset')].map(group => ({attendee_id: Number(group.dataset.id), route: group.querySelector('[name=route]').value, respondent_name: group.querySelector('[name=respondent_name]').value, email: group.querySelector('[name=email]').value}));
        try {render(await post({action: 'confirm', acknowledged: document.querySelector('#acknowledged').checked, respondents}));}
        catch {status.textContent = 'We couldn’t save your choices just now. Choose “Refresh waiver status” to see if they saved, or try the same choices again.'; document.querySelector('#ready').hidden = false;}
        finally {button.disabled = false;}
    };
    document.querySelector('#resume').onclick = resume;
    if (/^[a-f0-9]{64}$/.test(token)) {
        post({token, action: 'open'}).then(render).catch(unavailable).finally(() => {token = '';});
    } else { token = ''; resume(); }
})();
