<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta id="csrf-token" name="csrf-token" content="{{ csrf_token() }}">
    <title>Central de Projetos Git</title>
    <style>
        :root { --blue:#2563eb; --ink:#20304a; --muted:#718098; --line:#dfe6ef; --bg:#f3f6fb; --good:#11784b; --warn:#93630b; --danger:#b42318; }
        * { box-sizing:border-box } body { font:15px/1.5 Inter,Arial,sans-serif;background:var(--bg);color:var(--ink);margin:0 }.wrap{max-width:1080px;margin:32px auto;padding:15px} header{display:flex;justify-content:space-between;gap:16px;align-items:center} h1{font-size:26px;margin:0}h1 span{color:var(--blue)}p,small{color:var(--muted)}.tag,.badge{display:inline-flex;align-items:center;gap:6px;border-radius:22px;padding:7px 10px;font-size:12px;font-weight:700;background:#eaf1ff;color:#2458bd}.tag{background:#e4f7ee;color:#16764b}.app{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 15px 40px #14284b12;overflow:hidden}nav{display:flex;overflow:auto;border-bottom:1px solid #e3e9f2}nav button{padding:18px;border:0;background:#fff;color:#65758d;cursor:pointer;white-space:nowrap;font-weight:700;font:inherit}nav button.active{color:var(--blue);border-bottom:3px solid var(--blue)}section{display:none;padding:25px}section.active{display:block}h2{margin:0 0 5px;font-size:21px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin:20px 0}.card{border:1px solid #e2e9f2;border-radius:13px;padding:17px}.card strong{display:block;font-size:21px;margin:9px 0}.status{display:inline-block;padding:6px 9px;background:#fff3dc;color:var(--warn);border-radius:20px;font-size:12px;font-weight:700}.ok{background:#e3f8ec;color:var(--good)}.blue{background:#eaf1ff;color:#2458bd}.danger{background:#fff0ef;color:var(--danger)}select,textarea,input:not([type=checkbox]){width:100%;padding:12px;border:1px solid #dce4ef;border-radius:9px;background:#fff;font:inherit;margin:10px 0}.action{background:var(--blue);color:#fff;border:0;border-radius:10px;padding:12px 16px;cursor:pointer;margin:6px 6px 6px 0;font-weight:700;font:inherit}.secondary{background:#eaf1ff;color:#2458bd}.action:disabled{opacity:.5;cursor:not-allowed}.hint{background:#f0f5ff;padding:15px;border-radius:11px;margin:18px 0;color:#426293;font-size:13px}.item{display:flex;gap:11px;align-items:flex-start;padding:12px 0;border-bottom:1px solid #edf1f6}.item:last-child{border:0}.item span{flex:1}.item small{font-size:12px}.flow{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:22px 0}.flow .card{text-align:center;background:#f9fbfe}.emoji{font-size:26px}.branch{display:flex;align-items:center;gap:12px;border:1px solid #e2e9f2;border-radius:12px;padding:15px;margin:12px 0}.branch div{flex:1}.branch small{display:block;margin-top:4px}.terminal{margin:18px 25px 0;background:#0b1220;color:#d8e4ff;border-radius:12px;overflow:hidden;border:1px solid #1f2b42}.terminal-head{display:flex;justify-content:space-between;align-items:center;padding:9px 13px;background:#111c31;font-size:12px;font-weight:700}.terminal-dot{color:#77e6a5}.terminal-body{margin:0;padding:12px 14px;min-height:86px;max-height:175px;overflow:auto;font:12px/1.65 Consolas,"Courier New",monospace}.terminal-command{color:#86efac}.terminal-note{color:#9db4d9;margin:0 0 9px 17px;font:12px/1.4 Inter,Arial,sans-serif}.foot{text-align:center;font-size:12px;color:#8a96a8;margin:18px}.loader{display:inline-block;width:15px;height:15px;border:2px solid #c9d7f5;border-top-color:var(--blue);border-radius:50%;animation:spin .7s linear infinite;vertical-align:-2px}.hidden{display:none}.detail{font-size:12px;color:var(--muted);word-break:break-word}.count{font-variant-numeric:tabular-nums}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:650px){.grid,.flow{grid-template-columns:1fr}section{padding:17px}.terminal{margin:12px 17px 0}header{align-items:flex-start;flex-direction:column}.wrap{margin:12px auto}}@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
        .project-selection{display:grid;grid-template-columns:minmax(240px,320px) minmax(0,1fr);gap:13px;align-items:end;margin:16px 0 22px}.project-selection label,.add-project-fields label{display:block}.project-selection select,.project-selection .hint{margin:10px 0 0}.project-selection .hint{min-height:50px;display:flex;align-items:center}.add-project-fields{display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1fr);gap:23px}.add-project-fields select,.add-project-fields input{margin:10px 0 0}.add-project-actions{display:flex;align-items:center;flex-wrap:wrap;gap:2px;margin-top:10px}.add-project-actions .action{margin:6px 6px 6px 0}@media(max-width:650px){.project-selection,.add-project-fields{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="wrap">
    <header><div><h1><span>Central</span> de Projetos Git</h1><p>Do seu computador até a publicação, sem complicação.</p></div><div class="tag" id="connection">● Leitura local segura</div></header>
    <div class="app">
        <nav aria-label="Etapas da Central"><button class="active" data-tab="0">① Meus projetos</button><button data-tab="1">② Salvar alterações</button><button data-tab="2">③ Versões e caminhos</button><button data-tab="3">④ Publicações</button><button data-tab="4" title="Integrações e credenciais usadas fora da rotina.">⚙ Configuração</button></nav>
        <aside class="terminal" aria-live="polite" aria-label="Terminal didático, somente leitura"><div class="terminal-head"><span><span class="terminal-dot">●</span> Terminal didático <small>somente leitura</small></span><span id="terminalStatus">Aguardando ação</span></div><div class="terminal-body" id="terminal"><span class="terminal-command">$</span> Escolha um projeto ou clique em “Verificar alterações”.<p class="terminal-note">Aqui aparecerão os comandos reais executados pela Central, acompanhados de uma explicação.</p></div></aside>
        <section class="active">
            <h2>Em qual projeto vamos trabalhar? <small title="Um repositório local é a pasta do projeto acompanhada pelo Git.">?</small></h2>
            <p>Somente pastas autorizadas são lidas. Esta etapa não altera arquivos nem consulta a internet.</p>
            <div class="project-selection"><div><label for="project"><b>Projeto autorizado</b></label><select id="project" aria-label="Projeto autorizado"></select></div><div class="hint" id="path">Carregando caminho autorizado…</div></div>
            <div class="card"><h3>Adicionar projeto local</h3><p>Escolha uma pasta Git encontrada dentro da sua pasta-base. Nada é enviado à internet nesta etapa.</p><div class="add-project-fields"><label for="projectDirectory"><b>Pasta encontrada</b><select id="projectDirectory" aria-label="Pasta encontrada"></select></label><label for="projectLabel"><b>Nome para exibir</b><input id="projectLabel" maxlength="80" placeholder="Ex.: Meu site institucional"></label></div><div class="add-project-actions"><button class="action secondary" id="addProject" title="Autoriza apenas a pasta escolhida. A Central continuará bloqueando caminhos fora da pasta-base.">+ Adicionar projeto</button><small id="projectsRoot"></small></div></div>
            <div class="card" id="setupCard"><h3>Preparar este projeto para o GitHub</h3><div id="setupContent"><p>Escolha um projeto para ver os próximos passos seguros.</p></div></div>
            <div class="grid"><div class="card">Atualizações para salvar <small>(Commit)</small><strong class="count" id="count">—</strong><span class="status" id="status">Verificando</span></div><div class="card">Linha de trabalho <small>(Branch)</small><strong id="branch">—</strong><span class="status blue">Estado local</span></div><div class="card">Último registro <small>(Commit)</small><strong id="lastCommit">—</strong><span class="status" id="remoteStatus">Não consultado</span></div></div>
            <button class="action" id="refresh" title="Compara sua pasta com o último commit local. Não modifica arquivos.">↻ Verificar alterações <span class="hidden loader"></span></button><button class="action secondary" id="fetch" disabled title="Consulta o GitHub para atualizar a informação sobre commits novos no remoto. Não baixa nem substitui seus arquivos locais.">Consultar GitHub (Fetch)</button><button class="action secondary" id="goChanges" title="Abre a etapa onde você escolhe quais arquivos deseja preparar e salvar no histórico.">Ver arquivos →</button>
            <div class="hint" id="projectMessage">💡 “Verificar alterações” compara sua pasta com a última versão salva no histórico, sem modificar o projeto.</div>
        </section>
        <section>
            <h2>O que mudou? <small title="A Central lê o estado do Git; os botões de salvar serão liberados na próxima fase.">?</small></h2>
            <p>Escolha exatamente os arquivos que deseja preparar. A Central nunca executa <code>git add .</code>.</p>
            <div class="card" id="files"><p>Escolha um projeto para ver as alterações.</p></div>
            <button class="action secondary" id="stage" disabled title="Marca somente os arquivos escolhidos para entrar no próximo commit. Ainda não salva nem envia nada ao GitHub.">Preparar arquivos selecionados (Stage)</button>
            <label for="message" title="Explique em uma frase o que mudou. Essa frase ficará registrada no histórico do projeto."><b>Descrição desta atualização (Commit)</b></label><textarea id="message" rows="3" placeholder="Ex.: Corrige validação do cadastro" title="Escreva um resumo curto do que foi alterado; ele será usado no commit."></textarea>
            <button class="action" id="commit" disabled title="Cria um registro local com os arquivos preparados. Ainda não envia nada ao GitHub.">① Salvar no histórico (Commit)</button><button class="action secondary" id="push" disabled title="Envia ao GitHub somente os commits locais que já foram revisados e estão prontos.">② Enviar ao GitHub (Push)</button>
            <div class="hint" id="commitFeedback">🔒 Arquivos sensíveis, como <code>.env</code>, chaves e credenciais, são bloqueados. Push será habilitado após a integração GitHub.</div>
        </section>
        <section><h2>Versões e caminhos (Branches)</h2><p>As informações abaixo aguardam a integração real com o GitHub. A branch local atual aparece na primeira aba.</p><div class="branch">⌘ <div><b id="branchDetail">Nenhum projeto selecionado</b><small>Em uso neste computador — dado local confirmado quando o Git estiver disponível.</small></div><span class="status blue">Local</span></div><div class="hint">💡 “main” costuma ser a linha principal; “base” é normalmente o destino de um Pull Request. Nenhuma branch será trocada, criada ou removida nesta fase.</div></section>
        <section><h2>Onde está cada atualização?</h2><p>GitHub é acompanhado pelo Git local. A homologação pode ser consultada e publicada quando o ambiente estiver configurado; produção permanece bloqueada nesta etapa.</p><div class="flow"><div class="card"><div class="emoji">💻</div><strong>Computador</strong><small id="localSummary">Aguardando leitura</small></div><div class="card"><div class="emoji">☁️</div><strong>GitHub</strong><small>Via Git local</small></div><div class="card"><div class="emoji">🧪</div><strong>Homologação</strong><small>Consulta e publicação confirmada</small></div><div class="card"><div class="emoji">🚀</div><strong>Produção</strong><small>Bloqueada nesta etapa</small></div></div><div class="card"><b>Atualizações de banco (Migrations)</b><p id="migrations">Escolha um projeto para identificar arquivos de migration alterados.</p><small>Execução na homologação e produção: <b>não automática</b>. A Central não executa migrations durante a publicação desta etapa.</small></div><div class="card" id="githubSettingsCard"><h3>Conexão segura com GitHub</h3><p id="githubCredentialStatus">Verificando configuração…</p><label for="githubToken"><b>Token de acesso pessoal</b></label><input id="githubToken" type="password" autocomplete="off" spellcheck="false" placeholder="Cole um token do GitHub" title="O token será criptografado no servidor. Ele não será mostrado novamente nem enviado ao terminal."><p><button class="action" id="saveGitHubToken" title="Guarda o token criptografado para autenticar apenas fetch e push em repositórios HTTPS do GitHub.">Salvar token com segurança</button><button class="action secondary hidden" id="changeGitHubToken">Trocar token</button><button class="action secondary" id="removeGitHubToken" title="Apaga o token guardado pela Central. A consulta e o envio voltarão a depender das credenciais do Git instalado.">Remover token</button></p><small>Use um token de acesso pessoal com acesso somente aos repositórios necessários. Para consultar e enviar commits, use “Contents: Read and write” (somente consulta: “Contents: Read”). Para criar repositórios pela Central, o token também precisa de “Administration: Write”. O token não aparece novamente depois de salvo.</small></div><div class="hint">A publicação confirma a branch e o estado da homologação antes de iniciar o deploy. Produção e migrations não são alteradas por este fluxo.</div></section>
        <section id="configurationSection"><h2>⚙ Configuração</h2><p>Integrações e credenciais configuradas uma única vez. A rotina diária fica nas quatro primeiras abas.</p></section>
    </div><div class="foot">Fase 1: leitura local segura • dados exibidos diretamente do Git local</div>
</main>
<script>
const state={projects:[],current:null,directories:[],requestVersion:0}; const apiBase=@json(rtrim(request()->getBaseUrl(), '/').'/api');
const el=id=>document.getElementById(id); const csrfToken=()=>document.querySelector('meta[name="csrf-token"]')?.content ?? ''; const escapeHtml=value=>String(value ?? '').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
document.querySelectorAll('nav button').forEach(button=>button.addEventListener('click',()=>tab(Number(button.dataset.tab))));
function tab(index){document.querySelectorAll('nav button').forEach((b,i)=>b.classList.toggle('active',i===index));document.querySelectorAll('section').forEach((s,i)=>s.classList.toggle('active',i===index));}
async function request(url,options={}){const headers={Accept:'application/json',...options.headers};const response=await fetch(url,{...options,headers});if(!response.ok)throw new Error((await response.json()).message||'Não foi possível concluir a operação.');return response.json();}
function loading(on){el('refresh').disabled=on;el('project').disabled=on;el('refresh').querySelector('.loader').classList.toggle('hidden',!on);}
async function loadProjects(preferredId=null){loading(true);try{const data=await request(`${apiBase}/projects`);state.projects=data.projects;state.directories=data.available_directories||[];el('project').innerHTML=data.projects.map(p=>`<option value="${escapeHtml(p.id)}">${escapeHtml(p.label)}</option>`).join('');el('projectDirectory').innerHTML=state.directories.length?state.directories.map(directory=>`<option value="${escapeHtml(directory)}">${escapeHtml(directory)}</option>`).join(''):'<option value="">Nenhuma pasta Git encontrada</option>';el('addProject').disabled=!state.directories.length;el('projectsRoot').textContent=`Pasta-base: ${data.projects_root}. Para usar outra base, ajuste CENTRAL_PROJECTS_ROOT no .env.`;const selected=preferredId&&data.projects.some(project=>project.id===preferredId)?preferredId:el('project').value;el('project').value=selected;if(data.projects.length)await loadProject(selected);else message('Nenhum projeto autorizado foi configurado.','danger');}catch(error){message(error.message,'danger');}finally{loading(false)}}
async function loadProject(id){const requestVersion=++state.requestVersion;loading(true);try{const data=await request(`${apiBase}/projects/${encodeURIComponent(id)}`);if(requestVersion!==state.requestVersion)return;state.current=data.project;el('project').value=data.project.id;render(data.project);showTerminal(data.project.technical_commands,'Verificação concluída');}catch(error){if(requestVersion===state.requestVersion){message(error.message,'danger');showTerminal([],error.message);}}finally{if(requestVersion===state.requestVersion)loading(false)}}
function showTerminal(commands,status='Concluído'){el('terminalStatus').textContent=status;el('terminal').innerHTML=commands.length?commands.map(item=>`<div><span class="terminal-command">$ ${escapeHtml(item.command)}</span>${item.output!==undefined?`<pre>${escapeHtml(item.output)}</pre>`:''}<p class="terminal-note">↳ ${escapeHtml(item.purpose)}</p></div>`).join(''):'<span class="terminal-command">$</span> Nenhum comando concluído.';}
function message(text,type='blue'){el('projectMessage').innerHTML=`<span class="status ${type==='danger'?'danger':'blue'}">${type==='danger'?'Atenção':'Informação'}</span> ${escapeHtml(text)}`;}
function render(p){el('path').textContent=`📁 ${p.path}`;el('count').textContent=p.changes_count;el('branch').textContent=p.branch||'Sem Git';el('branchDetail').textContent=p.branch||'Não é um repositório Git';el('lastCommit').textContent=p.last_commit?.sha||'Sem commits';el('status').textContent=p.has_changes?'Precisam ser salvos':'Tudo salvo';el('status').className=`status ${p.has_changes?'':'ok'}`;el('remoteStatus').textContent=p.origin?(p.behind===null?'Remoto aguardando consulta':`${p.ahead||0} à frente · ${p.behind||0} atrás`):'Sem remoto';el('remoteStatus').className=`status ${p.origin?'blue':'danger'}`;el('localSummary').textContent=p.has_changes?`${p.changes_count} alteração(ões) local(is)`:p.is_git?'Sem alterações locais':'Git não detectado';el('migrations').innerHTML=(p.migrations||[]).length?`Há ${(p.migrations||[]).length} arquivo(s) alterado(s):<br>${p.migrations.map(m=>`<code>${escapeHtml(m.path)}</code>`).join('<br>')}`:'Nenhum arquivo de migration alterado foi identificado.';el('files').innerHTML=renderFiles(p);el('stage').disabled=!p.is_git||!p.has_changes;el('commit').disabled=!(p.changes.staged||[]).length;el('fetch').disabled=!p.origin;el('push').disabled=!(p.origin&&p.head&&((p.ahead>0&&p.behind===0)||p.ahead===null));updateCommitGuidance(p);renderSetup(p);const issue=p.error||(!p.is_git?'Esta pasta ainda não tem Git. Use o guia abaixo para iniciar com segurança.':null);message(issue||`${p.is_laravel?'Laravel detectado. ':'Projeto não identificado como Laravel. '}Leitura atual: ${p.changes_count} alteração(ões).`,issue?'danger':'blue');}
function renderSetup(p){if(!p.is_git){el('setupContent').innerHTML=`<p><b>Passo 1 de 4 — Iniciar Git</b></p><p>Cria uma pasta <code>.git</code> somente neste projeto. Seus arquivos não são enviados a lugar nenhum.</p><button class="action" data-setup="initialize">Iniciar Git neste projeto</button>`;return;}let html=`<p>✅ <b>Passo 1 de 4 concluído — Git iniciado.</b> Branch atual: <code>${escapeHtml(p.branch||'main')}</code>.</p>`;if(!p.identity_configured){const lastAuthor=p.last_commit?.author||'';const lastEmail=p.last_commit?.email||'';html+=`<p><b>Passo 2 de 4 — Identificar autor dos commits</b></p><p><small>Esta informação fica salva no próprio projeto. Ela será solicitada uma única vez.</small></p><input id="authorName" maxlength="120" placeholder="Seu nome" value="${escapeHtml(lastAuthor)}"><input id="authorEmail" type="email" maxlength="254" placeholder="seuemail@exemplo.com" value="${escapeHtml(lastEmail)}"><button class="action secondary" data-setup="identity">Salvar identidade neste projeto</button>`;}else{const identity=p.identity||{};const scope=identity.scope==='project'?'salva neste projeto':'configurada neste computador';html+=`<p>✅ <b>Passo 2 de 4 concluído — Autor dos commits.</b><br><code>${escapeHtml(identity.name)}</code> · <code>${escapeHtml(identity.email)}</code><br><small>Identidade ${scope}; não precisa ser informada novamente.</small></p>`;}if(!p.origin){html+=`<p><b>Passo 3 de 4 — Conectar repositório GitHub</b></p><input id="remoteUrl" placeholder="https://github.com/organizacao/repositorio.git" title="Use a URL HTTPS do repositório. Nunca cole token ou senha aqui."><button class="action secondary" data-setup="remote">Conectar repositório existente</button><details><summary>Criar um novo repositório no GitHub</summary><input id="repositoryOwner" maxlength="39" placeholder="Organização ou usuário (opcional)"><input id="repositoryName" maxlength="100" placeholder="nome-do-repositorio"><input id="repositoryDescription" maxlength="350" placeholder="Descrição curta do projeto"><label class="item"><input id="repositoryPrivate" type="checkbox"><span>Manter repositório privado</span></label><button class="action secondary" data-setup="create-remote">Criar e conectar repositório</button><small>Exige token do GitHub com “Administration: write”. O novo repositório será criado vazio, sem README ou LICENSE, para o primeiro envio ser simples.</small></details><small>Conectar apenas registra o endereço; ainda não envia arquivos.</small>`;}else{html+=`<p>✅ <b>Passo 3 de 4 concluído — GitHub conectado.</b><br><code>${escapeHtml(p.origin)}</code></p>`;if(p.head&&p.ahead===null){html+=`<p><b>Se o GitHub já tiver um README ou LICENSE:</b> consulte e integre esse histórico antes do primeiro envio.</p><button class="action secondary" data-setup="integrate">Integrar histórico do GitHub</button>`;}}if(p.identity_configured&&p.origin){html+=`<p>✅ <b>Passo 4 de 4 concluído — Projeto pronto.</b><br><small>Agora você pode verificar alterações, salvar commits e enviar atualizações sem refazer esta preparação.</small></p>`;}el('setupContent').innerHTML=html;}
function githubCredentialUrl(){return state.current?`${apiBase}/projects/${encodeURIComponent(state.current.id)}/github-credential`:null;}
function updateGitHubCredentialContext(){const heading=el('githubSettingsCard')?.querySelector('h3');if(!heading)return;const name=state.current?el('project').selectedOptions[0]?.textContent?.trim()||state.current.id:'selecione um projeto';heading.textContent=`Conexão segura com GitHub — por repositório (${name})`;}
async function loadGitHubCredential(){updateGitHubCredentialContext();const url=githubCredentialUrl();if(!url){el('githubCredentialStatus').textContent='ℹ️ Escolha um projeto para ver a credencial deste repositório.';return;}try{const data=await request(url);const configured=data.configured;const repository=data.scope||'repositório GitHub';const legacy=data.source==='legacy';el('githubCredentialStatus').textContent=configured?(legacy?`ℹ️ Há um token legado genérico. Salve o novo token de ${repository} abaixo para isolá-lo neste repositório.`:`✅ Token configurado para ${repository}. Ele não é exibido.`):`ℹ️ Nenhum token configurado para ${repository}.`;el('githubToken').disabled=configured&&!legacy;el('githubToken').value='';el('githubToken').placeholder=configured&&!legacy?'Token protegido — use “Trocar token” para alterar':'Cole o token deste repositório';el('saveGitHubToken').classList.toggle('hidden',configured&&!legacy);el('changeGitHubToken').classList.toggle('hidden',!configured||legacy);el('removeGitHubToken').disabled=!configured||legacy;}catch(error){el('githubCredentialStatus').textContent='⚠️ Não foi possível ler a configuração: '+error.message;}}
async function saveGitHubToken(){const url=githubCredentialUrl();const token=el('githubToken').value.trim();if(!url){el('githubCredentialStatus').textContent='⚠️ Escolha um projeto antes de salvar.';return;}if(!token){el('githubCredentialStatus').textContent='⚠️ Cole um token antes de salvar.';return;}el('saveGitHubToken').disabled=true;try{const data=await request(url,{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken()},body:JSON.stringify({token})});el('githubToken').value='';el('githubCredentialStatus').textContent='✅ '+data.message;await loadGitHubCredential();}catch(error){el('githubCredentialStatus').textContent='⚠️ '+error.message;}finally{el('saveGitHubToken').disabled=false;}}
async function removeGitHubToken(){const url=githubCredentialUrl();if(!url||!confirm('Remover o token guardado somente para este repositório?'))return;try{const data=await request(url,{method:'DELETE',headers:{'X-CSRF-TOKEN':csrfToken()}});el('githubCredentialStatus').textContent='ℹ️ '+data.message;await loadGitHubCredential();}catch(error){el('githubCredentialStatus').textContent='⚠️ '+error.message;}}
function startGitHubTokenReplacement(){el('githubToken').disabled=false;el('githubToken').value='';el('githubToken').placeholder='Cole o novo token deste repositório';el('saveGitHubToken').classList.remove('hidden');el('changeGitHubToken').classList.add('hidden');el('removeGitHubToken').disabled=true;el('githubCredentialStatus').textContent='ℹ️ Cole o novo token e salve para substituir somente a credencial deste repositório.';el('githubToken').focus();}
async function addProject(){const directory=el('projectDirectory').value;const label=el('projectLabel').value.trim()||directory;if(!directory)return;el('addProject').disabled=true;try{const data=await request(`${apiBase}/projects`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken()},body:JSON.stringify({directory,label})});el('projectLabel').value='';await loadProjects(data.project.id);message(`Projeto “${data.project.label}” autorizado neste computador. Nenhum arquivo foi modificado.`);}catch(error){message(error.message,'danger');}finally{el('addProject').disabled=false;}}
async function setupAction(action){if(!state.current)return;const base=`${apiBase}/projects/${encodeURIComponent(state.current.id)}`;let url,body=null,confirmation='';if(action==='initialize'){url=`${base}/initialize`;confirmation='Iniciar o Git nesta pasta? Isso criará apenas a pasta oculta .git; nenhum arquivo será enviado.';}if(action==='identity'){url=`${base}/identity`;body={name:el('authorName').value.trim(),email:el('authorEmail').value.trim()};}if(action==='remote'){url=`${base}/remote`;body={url:el('remoteUrl').value.trim()};confirmation='Conectar este projeto ao endereço informado do GitHub? Nenhum arquivo será enviado agora.';}if(action==='create-remote'){url=`${base}/github-repository`;body={owner:el('repositoryOwner').value.trim(),name:el('repositoryName').value.trim(),description:el('repositoryDescription').value.trim(),private:el('repositoryPrivate').checked};confirmation=`Criar ${body.private?'um repositório privado':'um repositório público'} no GitHub e conectá-lo a este projeto? Esta ação cria um recurso externo, mas ainda não envia arquivos.`;}if(action==='integrate'){url=`${base}/integrate-remote-history`;confirmation='Integrar o histórico já existente no GitHub? A Central fará fetch e merge somente se a pasta estiver sem alterações locais.';}if(confirmation&&!confirm(confirmation))return;try{const data=await request(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken()},body:body?JSON.stringify(body):null});const result=data.project||data;showTerminal(result.technical_commands||[],'Etapa concluída');message(result.message||'Etapa concluída.');await loadProject(state.current.id);}catch(error){message(error.message,'danger');showTerminal([{command:'Operação Git',purpose:'Executa somente a etapa confirmada.',output:'Falha: '+error.message}],'Etapa não concluída');}}
function updateCommitGuidance(p){const staged=(p.changes.staged||[]).length;const commitButton=el('commit');const stageButton=el('stage');if(!p.is_git){stageButton.textContent='Preparar arquivos selecionados (Git não detectado)';commitButton.textContent='① Salvar no histórico (Git não detectado)';el('commitFeedback').textContent='ℹ️ Inicie o Git neste projeto antes de salvar um commit.';return;}if(staged){stageButton.textContent='Preparar arquivos selecionados (Stage)';commitButton.textContent='① Salvar no histórico (Commit)';commitButton.title='Cria um registro local somente com os arquivos já preparados. Ainda não envia nada ao GitHub.';el('commitFeedback').textContent=`✅ ${staged} arquivo(s) preparado(s). Escreva a descrição e salve o commit local.`;return;}if(p.has_changes){stageButton.textContent='Preparar arquivos selecionados (escolha arquivos acima)';commitButton.textContent='① Salvar no histórico (prepare arquivos primeiro)';commitButton.title='Bloqueado até você escolher e preparar pelo menos um arquivo.';el('commitFeedback').textContent='👉 Próximo passo: marque os arquivos desejados acima e clique em “Preparar arquivos selecionados”. Só então o commit será liberado.';return;}stageButton.textContent='Preparar arquivos selecionados (sem alterações)';commitButton.textContent='① Salvar no histórico (sem alterações)';commitButton.title='Não há alterações locais para salvar.';el('commitFeedback').textContent='✅ Não há alterações locais aguardando commit.';}
function updateStageSelectionState(){const selected=document.querySelectorAll('#files input[type="checkbox"]:checked').length;if(!state.current||(state.current.changes.staged||[]).length)return;if(selected){el('stage').textContent=`Preparar ${selected} arquivo(s) selecionado(s)`;el('commitFeedback').textContent=`👉 ${selected} arquivo(s) selecionado(s). Agora clique em “Preparar ${selected} arquivo(s) selecionado(s)”.`;}else{updateCommitGuidance(state.current);}}
function renderFiles(p){if(!p.is_git)return '<p>Git não detectado nesta pasta. Nenhuma alteração pode ser lida.</p>';const labels={new:'Novos',modified:'Modificados',deleted:'Removidos',renamed:'Renomeados',staged:'Preparados (stage)',unstaged:'Não preparados',untracked:'Não rastreados'};const unique=new Map();Object.values(p.changes).flat().forEach(item=>unique.set(item.git_path,item));const selectable=[...unique.values()].filter(item=>!item.sensitive);let html=unique.size?`<h3>Selecionar para preparar</h3><p><button class="action secondary" type="button" id="selectSafeFiles" ${selectable.length?'':'disabled'} title="Marca apenas arquivos que a Central considera seguros. Arquivos com segredos nunca são preparados automaticamente.">Selecionar todos os arquivos seguros</button></p>`+[...unique.values()].map(item=>`<label class="item"><input type="checkbox" value="${escapeHtml(item.git_path)}" ${item.sensitive?'disabled':''}><span>📄 ${escapeHtml(item.path)}${item.sensitive?' 🔒':''}</span><small>${item.sensitive?'Arquivo sensível bloqueado':escapeHtml(item.code)}</small></label>`).join(''):'';for(const [key,label] of Object.entries(labels)){const items=p.changes[key]||[];if(!items.length)continue;html+=`<h3>${label} <small>(${items.length})</small></h3>`+items.map(item=>`<div class="item"><span>📄 ${escapeHtml(item.path)}${item.sensitive?' 🔒':''}</span><small>${item.sensitive?'Arquivo sensível bloqueado':escapeHtml(item.code)}</small></div>`).join('');}return html||'<p>✅ Nenhuma alteração local encontrada.</p>';}
async function stage(){const files=[...document.querySelectorAll('#files input:checked')].map(input=>input.value);if(!files.length){el('commitFeedback').textContent='Selecione pelo menos um arquivo antes de preparar.';return;}el('stage').disabled=true;try{const result=await request(`${apiBase}/projects/${encodeURIComponent(state.current.id)}/stage`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken()},body:JSON.stringify({files})});showTerminal(result.technical_commands,'Arquivos preparados');el('commitFeedback').textContent='✅ Arquivos preparados. Revise a mensagem e salve o commit localmente.';await loadProject(state.current.id);}catch(error){el('commitFeedback').textContent='⚠️ '+error.message;}finally{el('stage').disabled=false;}}
async function commit(){const message=el('message').value.trim();if(!message){el('commitFeedback').textContent='Escreva uma descrição antes de salvar o commit.';return;}if(!confirm('Salvar o commit local com os arquivos já preparados? Isso não envia nada ao GitHub.'))return;el('commit').disabled=true;try{const result=await request(`${apiBase}/projects/${encodeURIComponent(state.current.id)}/commit`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken()},body:JSON.stringify({message})});showTerminal(result.commit.technical_commands,'Commit local concluído');el('message').value='';el('commitFeedback').textContent=`✅ Commit ${result.commit.sha} salvo localmente. O envio ao GitHub ainda não foi realizado.`;await loadProject(state.current.id);}catch(error){el('commitFeedback').textContent='⚠️ '+error.message;}finally{el('commit').disabled=false;}}
async function fetchRemote(){el('fetch').disabled=true;try{const data=await request(`${apiBase}/projects/${encodeURIComponent(state.current.id)}/fetch`,{method:'POST',headers:{'X-CSRF-TOKEN':csrfToken()}});render(data.project);showTerminal(data.project.technical_commands,'Consulta ao GitHub concluída');message('Consulta ao GitHub concluída. Nenhum arquivo local foi alterado.');}catch(error){message(error.message,'danger');showTerminal([{command:'git fetch origin',purpose:'Consulta os commits disponíveis no GitHub sem baixar arquivos.',output:'Falha: '+error.message}],'Consulta não concluída');}finally{el('fetch').disabled=false;}}
async function push(){if(!confirm('Enviar os commits locais ao GitHub? Não há força nem sobrescrita neste fluxo.'))return;el('push').disabled=true;try{const data=await request(`${apiBase}/projects/${encodeURIComponent(state.current.id)}/push`,{method:'POST',headers:{'X-CSRF-TOKEN':csrfToken()}});showTerminal(data.technical_commands,'Envio ao GitHub concluído');el('commitFeedback').textContent=`✅ ${data.message} Commit ${data.head}.`;await loadProject(state.current.id);}catch(error){el('commitFeedback').textContent='⚠️ '+error.message;}finally{el('push').disabled=false;}}
const publicSection=document.querySelectorAll('section')[3];publicSection.insertAdjacentHTML('beforeend','<div class="card"><h3>Publicar na homologação</h3><p>Envia para o Laravel Cloud a branch configurada deste projeto, somente após sua confirmação. Migrations não são executadas automaticamente.</p><button class="action" id="deployCloud" disabled title="Inicia uma publicação no ambiente de homologação configurado. Não publica em produção e não executa migrations automaticamente.">Publicar na homologação</button><p id="deployResult" class="detail">Configure e verifique o Laravel Cloud antes de publicar.</p></div>');publicSection.insertAdjacentHTML('beforeend','<div class="card"><h3>Configuração avançada — Laravel Cloud</h3><p id="cloudCredentialStatus">Verificando configuração…</p><label for="cloudToken"><b>Token do Laravel Cloud</b></label><input id="cloudToken" type="password" autocomplete="off" spellcheck="false" placeholder="Cole o token do Laravel Cloud"><p><button class="action" id="saveCloudToken">Salvar token com segurança</button><button class="action secondary hidden" id="changeCloudToken">Trocar token</button><button class="action secondary hidden" id="removeCloudToken">Remover token</button></p><button class="action secondary" id="verifyCloud">Verificar homologação deste projeto</button><p id="cloudResult" class="detail">A verificação apenas consulta o ambiente. Deploy e migrations continuam bloqueados.</p></div>');async function loadCloudCredential(){try{const data=await request(`${apiBase}/laravel-cloud-credential`);const configured=data.configured;el('cloudCredentialStatus').textContent=configured?'✅ Token configurado e protegido. Ele não é exibido.':'ℹ️ Nenhum token Laravel Cloud configurado.';el('cloudToken').disabled=configured;el('cloudToken').value='';el('cloudToken').placeholder=configured?'Token protegido — use “Trocar token” para alterar':'Cole o token do Laravel Cloud';el('saveCloudToken').classList.toggle('hidden',configured);el('changeCloudToken').classList.toggle('hidden',!configured);el('removeCloudToken').classList.toggle('hidden',!configured);el('verifyCloud').disabled=!configured;el('deployCloud').disabled=!configured;}catch(error){el('cloudCredentialStatus').textContent='⚠️ '+error.message;}}async function saveCloudToken(){const token=el('cloudToken').value.trim();if(!token){el('cloudCredentialStatus').textContent='⚠️ Cole um token antes de salvar.';return;}try{const data=await request(`${apiBase}/laravel-cloud-credential`,{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken()},body:JSON.stringify({token})});el('cloudToken').value='';el('cloudCredentialStatus').textContent='✅ '+data.message;await loadCloudCredential();}catch(error){el('cloudCredentialStatus').textContent='⚠️ '+error.message;}}function startCloudTokenReplacement(){el('cloudToken').disabled=false;el('cloudToken').value='';el('cloudToken').placeholder='Cole o novo token do Laravel Cloud';el('saveCloudToken').classList.remove('hidden');el('changeCloudToken').classList.add('hidden');el('removeCloudToken').classList.add('hidden');el('cloudCredentialStatus').textContent='ℹ️ Cole o novo token e salve para substituir a credencial atual.';el('cloudToken').focus();}async function removeCloudToken(){if(!confirm('Remover o token Laravel Cloud guardado pela Central? A consulta da homologação ficará bloqueada até uma nova configuração.'))return;try{const data=await request(`${apiBase}/laravel-cloud-credential`,{method:'DELETE',headers:{'X-CSRF-TOKEN':csrfToken()}});el('cloudCredentialStatus').textContent='ℹ️ '+data.message;await loadCloudCredential();}catch(error){el('cloudCredentialStatus').textContent='⚠️ '+error.message;}}function updateStageFromFiles(event){if(event.target.id==='selectSafeFiles'){el('files').querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(input=>input.checked=true);}updateStageSelectionState();}async function verifyCloud(){if(!state.current)return;el('verifyCloud').disabled=true;try{const data=await request(`${apiBase}/projects/${encodeURIComponent(state.current.id)}/laravel-cloud/verify`,{method:'POST',headers:{'X-CSRF-TOKEN':csrfToken()}});const environment=data.environment;el('cloudResult').textContent=`✅ ${environment.application}/${environment.environment}: branch ${environment.branch||'não informada'} (${environment.branch_matches?'confere':'não confere com '+environment.expected_branch}), status ${environment.status||'não informado'}.`;showTerminal(environment.technical_commands,'Homologação consultada');}catch(error){el('cloudResult').textContent='⚠️ '+error.message;}finally{el('verifyCloud').disabled=false;}}async function deployCloud(){if(!state.current)return;if(!confirm('Iniciar a publicação na homologação do projeto selecionado? A Central conferirá a branch antes de publicar. Produção e migrations não serão alteradas.'))return;el('deployCloud').disabled=true;el('deployResult').textContent='⏳ Iniciando a publicação e acompanhando o Laravel Cloud…';try{const data=await request(`${apiBase}/projects/${encodeURIComponent(state.current.id)}/laravel-cloud/deploy-homologation`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken()},body:JSON.stringify({confirmed:true})});const deployment=data.publication.deployment||{};const status=deployment.status||data.publication.monitoring||'em andamento';el('deployResult').textContent=`✅ Publicação solicitada. Status atual: ${status}. Migrations não foram executadas automaticamente.`;showTerminal(data.publication.technical_commands,'Publicação na homologação iniciada');}catch(error){el('deployResult').textContent='⚠️ '+error.message;}finally{el('deployCloud').disabled=false;}}el('project').addEventListener('change',e=>loadProject(e.target.value));el('refresh').addEventListener('click',()=>loadProject(el('project').value));el('fetch').addEventListener('click',fetchRemote);el('goChanges').addEventListener('click',()=>tab(1));el('stage').addEventListener('click',stage);el('commit').addEventListener('click',commit);el('push').addEventListener('click',push);el('saveGitHubToken').addEventListener('click',saveGitHubToken);el('changeGitHubToken').addEventListener('click',startGitHubTokenReplacement);el('removeGitHubToken').addEventListener('click',removeGitHubToken);el('saveCloudToken').addEventListener('click',saveCloudToken);el('changeCloudToken').addEventListener('click',startCloudTokenReplacement);el('removeCloudToken').addEventListener('click',removeCloudToken);el('verifyCloud').addEventListener('click',verifyCloud);el('deployCloud').addEventListener('click',deployCloud);el('addProject').addEventListener('click',addProject);el('setupContent').addEventListener('click',event=>{const action=event.target.dataset.setup;if(action)setupAction(action);});el('files').addEventListener('click',updateStageFromFiles);el('files').addEventListener('change',updateStageFromFiles);loadProjects();loadGitHubCredential();loadCloudCredential();
const configurationSection=el('configurationSection');
configurationSection.append(el('githubSettingsCard'));
configurationSection.append(publicSection.lastElementChild);
</script>
<script>
const migrationCard = document.querySelector('#migrations').closest('.card');
migrationCard.insertAdjacentHTML('beforeend', '<button class="action secondary" id="checkMigrations" disabled title="Consulta o banco da homologação com migrate:status. Não executa migrations.">Consultar migrations da homologação</button><p id="migrationResult" class="detail">A consulta remota fica disponível após configurar o Laravel Cloud.</p>');

const migrationButton = document.querySelector('#checkMigrations');
const migrationResult = document.querySelector('#migrationResult');
const migrationApiBase = new URL('api/', window.location.href).toString().replace(/\/$/, '');
const migrationCsrfToken = document.querySelector('meta[name="csrf-token"]').content;
let migrationCommandId = null;

async function migrationRequest(url, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 45000);
    try {
        const response = await fetch(url, {...options, signal: controller.signal});
        const data = await response.json();
        if (! response.ok) {
            throw new Error(data.message || 'Não foi possível concluir a consulta.');
        }

        return data;
    } catch (error) {
        if (error.name === 'AbortError') {
            throw new Error('A consulta excedeu 45 segundos. O botão foi liberado; tente atualizar o resultado novamente.');
        }

        throw error;
    } finally {
        clearTimeout(timeout);
    }
}

async function loadMigrationAvailability() {
    try {
        const data = await migrationRequest(`${migrationApiBase}/laravel-cloud-credential`);
        migrationButton.disabled = ! data.configured;
    } catch (error) {
        migrationResult.textContent = `⚠️ ${error.message}`;
    }
}

async function checkMigrations() {
    if (! state.current) {
        migrationResult.textContent = '⚠️ Escolha um projeto antes de consultar as migrations.';
        return;
    }

    migrationButton.disabled = true;
    migrationResult.textContent = '⏳ Consultando o banco da homologação, sem executar migrations…';

    try {
        const data = await migrationRequest(`${migrationApiBase}/projects/${encodeURIComponent(state.current.id)}/laravel-cloud/migration-status`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': migrationCsrfToken},
            body: JSON.stringify(migrationCommandId ? {command_id: migrationCommandId} : {}),
        });
        const result = data.migration_status;
        migrationCommandId = result.command_id || null;
        if (! result.finished) {
            migrationResult.textContent = `⏳ Consulta em andamento no Laravel Cloud (status: ${result.command_status || 'aguardando'}). Clique em “Atualizar resultado” em alguns segundos. A tela não ficará bloqueada.`;
            migrationButton.textContent = 'Atualizar resultado';
            showTerminal(result.technical_commands, 'Consulta remota em andamento');
            return;
        }
        const pending = Number(result.pending_count || 0);
        migrationResult.textContent = pending
            ? `⚠️ ${pending} migration(s) pendente(s) no banco da homologação.`
            : '✅ Nenhuma migration pendente foi identificada na homologação.';
        migrationButton.textContent = 'Consultar migrations da homologação';
        migrationCommandId = null;
        showTerminal([{
            command: result.technical_commands.at(-1).command,
            purpose: result.technical_commands.at(-1).purpose,
            output: result.output,
        }], 'Migrations consultadas');
    } catch (error) {
        migrationResult.textContent = `⚠️ ${error.message}`;
        migrationButton.textContent = migrationCommandId ? 'Atualizar resultado' : 'Consultar migrations da homologação';
    } finally {
        migrationButton.disabled = false;
    }
}

migrationButton.addEventListener('click', checkMigrations);
loadMigrationAvailability();
</script>
<script>
const deploymentCard = document.querySelector('#deployCloud').closest('.card');
deploymentCard.insertAdjacentHTML('beforeend', '<button class="action secondary" id="checkDeployment" disabled title="Compara o SHA atual no GitHub com o SHA registrado pelo último deploy no Laravel Cloud.">Confirmar versão implantada</button><p id="deploymentResult" class="detail">A confirmação de versão fica disponível após configurar o Laravel Cloud.</p>');

const deploymentButton = document.querySelector('#checkDeployment');
const deploymentResult = document.querySelector('#deploymentResult');
const deploymentApiBase = new URL('api/', window.location.href).toString().replace(/\/$/, '');
const deploymentCsrfToken = document.querySelector('meta[name="csrf-token"]').content;
let deploymentComparison = null;

function applyPublicationGate() {
    const publishButton = el('deployCloud');
    const currentProjectId = state.current?.id;
    const comparisonIsCurrent = deploymentComparison !== null && deploymentComparison.projectId === currentProjectId;
    const hasNewVersion = comparisonIsCurrent && deploymentComparison.matches === false;

    publishButton.disabled = ! hasNewVersion;
    publishButton.title = hasNewVersion
        ? 'Publica a nova versão confirmada no GitHub para a homologação. Migrations não serão executadas automaticamente.'
        : comparisonIsCurrent && deploymentComparison.matches
            ? 'Não há nova versão para publicar: a homologação já usa o mesmo commit do GitHub.'
            : 'Primeiro use “Confirmar versão implantada”. A publicação só é liberada quando existir uma versão nova no GitHub.';
}

async function deploymentRequest(url, options = {}) {
    const response = await fetch(url, options);
    const data = await response.json();
    if (! response.ok) {
        throw new Error(data.message || 'Não foi possível confirmar a versão.');
    }

    return data;
}

async function loadDeploymentAvailability() {
    try {
        const data = await deploymentRequest(`${deploymentApiBase}/laravel-cloud-credential`);
        deploymentButton.disabled = ! data.configured;
    } catch (error) {
        deploymentResult.textContent = `⚠️ ${error.message}`;
    }
}

async function checkDeployment() {
    if (! state.current) {
        deploymentResult.textContent = '⚠️ Escolha um projeto antes de confirmar a versão.';
        return;
    }

    deploymentButton.disabled = true;
    deploymentButton.innerHTML = 'Consultando versão <span class="loader"></span>';
    deploymentResult.innerHTML = '<span class="hourglass-spin" aria-hidden="true">⌛</span> Comparando o GitHub com o Laravel Cloud…';
    try {
        const data = await deploymentRequest(`${deploymentApiBase}/projects/${encodeURIComponent(state.current.id)}/laravel-cloud/deployment-status`, {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': deploymentCsrfToken},
        });
        const result = data.deployment;
        const expected = result.expected_commit || 'não informado';
        const deployed = result.deployed_commit || 'não informado';
        deploymentComparison = {projectId: state.current.id, matches: result.matches === true};
        deploymentResult.textContent = result.matches
            ? `✅ Versão implantada: SHA ${deployed}. É a mesma versão do GitHub.`
            : `⚠️ Versão implantada: SHA ${deployed}. O GitHub possui uma versão nova: ${expected}.`;
        el('deployResult').textContent = result.matches
            ? 'ℹ️ Não há uma versão nova para publicar: a homologação já está na mesma versão do GitHub.'
            : '✅ Há uma versão nova confirmada no GitHub. A publicação na homologação foi liberada.';
        updatePublicationVersionSummary(result, expected, deployed);
        showTerminal(result.technical_commands, 'Versão implantada confirmada');
    } catch (error) {
        deploymentResult.textContent = `⚠️ ${error.message}`;
    } finally {
        deploymentButton.disabled = false;
        deploymentButton.textContent = 'Confirmar versão implantada';
        applyPublicationGate();
    }
}

deploymentButton.addEventListener('click', checkDeployment);
el('project').addEventListener('change', () => { deploymentComparison = null; applyPublicationGate(); });
el('deployCloud').addEventListener('click', event => { if (el('deployCloud').disabled) { event.stopImmediatePropagation(); el('deployResult').textContent = 'ℹ️ Antes de publicar, confirme a versão implantada. A Central liberará este botão somente se houver uma versão nova.'; } }, true);
const initialPublicationGate = setInterval(() => applyPublicationGate(), 200);
setTimeout(() => clearInterval(initialPublicationGate), 10000);
applyPublicationGate();
loadDeploymentAvailability();
setTimeout(loadDeploymentAvailability, 1500);
</script>
<script>
const scopedGitHubCard = document.querySelector('#githubSettingsCard');
scopedGitHubCard.querySelector('h3').textContent = 'Conexão segura com GitHub — por repositório';
scopedGitHubCard.querySelector('small').textContent = 'Cada token fica criptografado e associado apenas ao repositório selecionado. Para consultar o GitHub, use “Contents: Read”. Para enviar commits, use “Contents: Read and write”. Não use um token amplo para projetos públicos e privados.';
document.querySelector('#project').addEventListener('change', event => { const selectedId=event.target.value; const refreshScopedCredential=setInterval(() => { if(state.current?.id===selectedId){clearInterval(refreshScopedCredential);loadGitHubCredential();} },100); });
const scopedCredentialReady = setInterval(() => { if (state.current) { clearInterval(scopedCredentialReady); loadGitHubCredential(); } }, 150);
</script>
<script>
const publicationCard = document.querySelector('#deployCloud').closest('.card');
publicationCard.insertAdjacentHTML('afterbegin', '<p id="homologationPublicationStatus" class="detail">ℹ️ Homologação ainda não verificada nesta sessão.</p>');

function rememberHomologation(environment) {
    const status = environment.status || 'não informado';
    const branch = environment.branch || 'não informada';
    const verified = environment.branch_matches && status === 'running';
    const text = verified
        ? `✅ Homologação verificada: branch ${branch}, ambiente em execução.`
        : `⚠️ Homologação consultada: branch ${branch}, status ${status}. Publicação permanece bloqueada até a verificação ficar válida.`;
    document.querySelector('#homologationPublicationStatus').textContent = text;
}

async function verifyCloudAndRemember(event) {
    event.stopImmediatePropagation();
    if (! state.current) {
        return;
    }

    const verifyButton = el('verifyCloud');
    verifyButton.disabled = true;
    try {
        const data = await request(`${apiBase}/projects/${encodeURIComponent(state.current.id)}/laravel-cloud/verify`, {
            method: 'POST', headers: {'X-CSRF-TOKEN': csrfToken()},
        });
        const environment = data.environment;
        el('cloudResult').textContent = `✅ ${environment.application}/${environment.environment}: branch ${environment.branch || 'não informada'} (${environment.branch_matches ? 'confere' : 'não confere com '+environment.expected_branch}), status ${environment.status || 'não informado'}.`;
        rememberHomologation(environment);
        showTerminal(environment.technical_commands, 'Homologação consultada');
    } catch (error) {
        el('cloudResult').textContent = `⚠️ ${error.message}`;
        document.querySelector('#homologationPublicationStatus').textContent = '⚠️ Não foi possível verificar a homologação. Veja o erro na configuração.';
    } finally {
        verifyButton.disabled = false;
    }
}

el('verifyCloud').addEventListener('click', verifyCloudAndRemember, true);
</script>
<style>
    .hourglass-spin { display: inline-block; animation: spin 1.4s linear infinite; transform-origin: center; }
    @media (prefers-reduced-motion: reduce) { .hourglass-spin { animation: none; } }
</style>
<script>
const publicationFlowCards = document.querySelectorAll('section')[3].querySelectorAll('.flow .card');
const githubPublicationSummary = publicationFlowCards[1].querySelector('small');
const homologationPublicationSummary = publicationFlowCards[2].querySelector('small');
const productionPublicationSummary = publicationFlowCards[3].querySelector('small');

function shortSha(sha) {
    return typeof sha === 'string' && sha.length >= 7 ? sha.slice(0, 7) : 'não informado';
}

function updatePublicationVersionSummary(result, expected, deployed) {
    const branch = result.environment?.expected_branch || 'configurada';
    githubPublicationSummary.textContent = `GitHub: branch ${branch}, versão ${shortSha(expected)}`;
    homologationPublicationSummary.textContent = result.matches
        ? `Versão implantada: ${shortSha(deployed)} ✓`
        : `Versão implantada: ${shortSha(deployed)} — há atualização ${shortSha(expected)}`;
    productionPublicationSummary.textContent = 'Produção não configurada';
}

let publicationLocalSignature = null;
setInterval(() => {
    const project = state.current;
    if (! project) {
        return;
    }

    const value = project.has_changes
        ? `${project.changes_count} alteração(ões) no disco — aguardando commit`
        : 'Nenhuma alteração local aguardando commit';
    if (publicationLocalSignature !== value) {
        publicationLocalSignature = value;
        el('localSummary').textContent = value;
    }
}, 250);
</script>
</body></html>
