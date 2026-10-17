# ESPECIFICAÇÃO PARA O CODEX — Central de Projetos Git

## 1. Missão e fonte visual

Construir **uma aplicação real, local, funcional, em português do Brasil**, chamada provisoriamente **Central de Projetos Git**, para iniciantes que trabalham com XAMPP, PHP/Laravel, GitHub e Laravel Cloud. O arquivo HTML fornecido junto a esta especificação é a **referência principal de aparência, organização e navegação**. Reaproveite seu design, mantendo as quatro abas e melhorando os detalhes necessários, mas substitua simulações por dados e operações reais. Não criar apenas outro mockup.

Diretório da nova aplicação, já criado: `C:\xampp\htdocs\centralprojetosgit`. Projetos iniciais a descobrir: `C:\xampp\htdocs\webe` e `C:\xampp\htdocs\webesolutions`. Descobrir outros projetos futuramente sob `C:\xampp\htdocs`, sem confundir a própria Central com um projeto a publicar. Não modificar projetos existentes sem ação explícita do usuário.

Pode usar **Laravel** e a versão de **PHP disponível e compatível**. Verifique versões reais do PHP, Composer, Git, Node e Laravel antes de instalar dependências; não presumir versões nem executar atualizações globais. Usar MySQL local ou SQLite para **dados internos da Central** (preferir opção mais simples de configurar), nunca compartilhar suas tabelas com bancos dos projetos gerenciados.

## 2. Princípios de UX

- Interface compacta, responsiva, acessível, amigável, sem aparência de terminal. PT-BR em toda a experiência; termos técnicos em parênteses: “Salvar alterações (Commit)”, “Enviar ao GitHub (Push)”, “Linha de trabalho (Branch)”, “Publicar (Deploy)”, “Atualização de banco (Migration)”, “Reverter (Rollback/Revert)”.
- Preservar as abas: **Meus projetos**, **Salvar alterações**, **Versões e caminhos**, **Publicações**. Mostrar títulos, descrições e dicas contextualizadas com nomenclatura Git/GitHub/Laravel Cloud. Tooltip `?` com explicação de uma frase e exemplo.
- Ícones consistentes (Lucide ou similar), microanimações discretas de carregamento, transição de status e confirmação; respeitar `prefers-reduced-motion`. Cores sem depender exclusivamente delas: ícones, texto e badges para estados.
- Tela inicial com seletor de projeto e visão rápida: arquivos alterados, commits locais não enviados, commits remotos ainda não incorporados, branch atual, homologação, produção, migrations e último erro. Nunca apresentar dados simulados como reais.
- Linguagem orientada à ação: “Você tem 3 arquivos alterados”, “2 registros ainda não enviados”, “Homologação está na versão X; produção na Y”, “Há uma atualização de banco a revisar”. Se não for possível determinar um estado, mostrar **“Não foi possível confirmar”**, não “Atualizado”.
- Botão principal de **próximo passo recomendado** e ações perigosas sob confirmação separada. Não despejar comandos Git na tela principal; disponibilizar detalhes técnicos em área expansível.

## 3. Leitura dos projetos locais

- Backend Laravel local faz descoberta **somente** de diretórios autorizados dentro de `C:\xampp\htdocs`; não tentar ler disco pelo JavaScript do navegador. Permitir cadastro manual e exclusão de projetos da lista, sem apagar pastas.
- Detectar se há `.git`, `composer.json`, `artisan`, arquivos de configuração, remote `origin`, branch ativa, HEAD, estado limpo/sujo e existência de commits. Diferenciar projeto sem Git, sem remote, sem acesso ao GitHub, sem Laravel e projeto com mudanças não rastreadas.
- Usar Git CLI local via processo seguro, argumentos parametrizados, diretório de trabalho validado, sem concatenar comandos de shell com entrada do usuário. Não ler ou expor `.env`, tokens, chaves, senhas, `vendor`, `node_modules`, logs ou dados privados. Honrar `.gitignore` e alertar antes de incluir arquivos sensíveis.
- Exibir alterações separadas em: novos, modificados, removidos, renomeados, staged, unstaged e não rastreados. Permitir ver diff textual seguro e selecionar arquivos para stage. Commit com mensagem obrigatória; **não executar `git add .` cegamente**.
- Depois do commit, mostrar “salvo localmente”; só após push confirmado mostrar “enviado ao GitHub”. `fetch` antes de comparar referências remotas, sem alterar arquivos locais. Distinguir ahead/behind/diverged e conflitos.

## 4. GitHub e branches sem confusão

- Integrar GitHub REST API e/ou GraphQL **conforme necessidade**, com autenticação segura por GitHub App ou OAuth (ou token com escopo mínimo na primeira versão), credenciais criptografadas no backend e nunca expostas ao front-end. Para operações locais, usar Git CLI. Documentar configuração e permissões.
- Explicar que **main** costuma ser o nome de uma branch principal, **base** normalmente significa a branch de destino de um Pull Request, e **HEAD** aponta para o commit/branch atualmente selecionado. Não tratar “base” como branch obrigatória.
- Mostrar apenas branches relevantes por padrão: atual, padrão (default), vinculadas à homologação/produção, com commits exclusivos recentes, PR aberto ou escolhidas como favoritas. Colocar branches antigas em “Ver outras”, com último commit, autor, data, merge/PR e aviso de possível obsolescência; não deletar automaticamente.
- Cada branch deve ter etiqueta de finalidade **derivada de configuração verificada**, não de suposição: “Em uso no computador”, “Principal do repositório”, “Origem da homologação”, “Origem da produção”, “Em revisão”, “Sem função configurada”. Uma branch pode acumular etiquetas.
- Antes de mudar de branch, verificar alterações locais, estado remoto e risco de conflito. Proibir `reset --hard`, force push e sobrescrita silenciosa no fluxo comum. Nunca realizar merge ou rebase automático sem explicação, prévia e confirmação.
- Pull Request: mostrar **origem (head)** e **destino (base)** em linguagem comum; comparar diferenças, revisões e status checks. Se houver API/webhook útil para status, usar. Configurar proteção de branches e impedir publicação de commits não aprovados quando assim definido.

## 5. Laravel Cloud e ambientes

- Investigar na documentação oficial **atual** do Laravel Cloud se há API pública, endpoints autorizados, webhooks, integração com GitHub, permissões e como consultar ambientes, deploys, branch vinculada, commit SHA, status, logs, URL, início/fim, falha e rollback. **Não inventar API, endpoint ou capacidade**. Implementar adaptador `CloudProvider` desacoplado; usar API oficial se disponível. Se não houver acesso programático adequado, usar dados verificáveis via GitHub Actions/statuses/deployment API e configuração manual, identificando explicitamente o que é inferido e o que é confirmado; oferecer link para painel Cloud. Nunca fazer scraping de sessão privada.
- Para cada projeto, permitir configurar repositório, ambiente de homologação e produção, branch ou referência de origem, estratégia de publicação e URL. Não assumir que push gera deploy automático nem que branch `main` corresponde à produção.
- Fluxo obrigatório: **local → GitHub → homologação → aprovação explícita → produção**. Mostrar o **SHA exato** publicado em cada ambiente; comparar SHA/ancestralidade e distinguir “mesma versão”, “produção atrasada”, “ambientes divergentes” e “status desconhecido”. Exigir sucesso real do deploy e verificação de saúde configurada antes de oferecer promoção.
- Preferir **promover exatamente o commit validado em homologação**, sem introduzir commits diferentes no meio. Se a configuração do Cloud não permitir promoção direta, implementar fluxo de PR/merge controlado, avisando quando o SHA efetivo for diferente (por exemplo, merge commit) e exigindo revalidação apropriada.
- Não fazer deploy direto de produção por engano. Exigir resumo das diferenças, confirmação textual, ambiente correto, backup disponível quando aplicável, checks, migrations e rollback planejado.

## 6. Banco de dados e migrations

- Detectar migrations Laravel novas/alteradas no Git e exibir quais arquivos entram no commit. **Não confundir arquivo de migration pendente no repositório com migration pendente de execução no banco remoto.**
- Para detectar execução pendente em cada ambiente, usar **integração autorizada e segura** com comandos do Laravel (por exemplo, `php artisan migrate:status` executado no ambiente correspondente por mecanismo oficial disponível), nunca consultar banco remoto por conta própria nem deduzir status apenas pelo Git. Sem integração, mostrar “Execução no ambiente não verificada”.
- Em homologação, exibir preview/planejamento, dependências e risco; executar migrations somente por processo de deploy autorizado e configurado. Em produção, exigir confirmação adicional, política de backup e manutenção. Evitar `migrate:fresh`, `db:wipe`, `migrate:reset` e `--force` sem fluxo protegido específico; **nunca** executar operações destrutivas automaticamente.
- Rollback de migration pode ser destrutivo e não equivale a reverter código. Mostrar avisos e orientar para migration corretiva/backup restaurável quando apropriado. Considerar compatibilidade entre versões do código e esquema, migrations aditivas e estratégia expand/contract.
- Separar visualmente **migrations da própria Central** das migrations dos projetos gerenciados.

## 7. Reversão e recuperação

- Disponibilizar histórico de commits e deploys com autor, data, mensagem, SHA abreviado, ambiente e status. Oferecer “Voltar para versão anterior” apenas após identificar exatamente **o que** será revertido: código local, commit no GitHub, deploy em homologação ou produção.
- Para código compartilhado, preferir `git revert` em novo commit e PR em vez de reescrever histórico. Para deploy, usar rollback oficial do provedor **se confirmado e suportado**. Não prometer rollback automático do banco de dados. Apresentar prévia, consequências, backups e confirmação; registrar auditoria.
- Nunca substituir trabalho mais novo sem comparar ancestry/diff e sem consentimento. Evitar `push --force`, `reset --hard`, exclusão de branch e operações destrutivas no fluxo padrão.

## 8. Segurança e arquitetura

- Central roda **somente localmente por padrão**, com autenticação local simples ou controle de acesso apropriado, proteção CSRF, validação de caminhos com canonicalização/realpath, bloqueio de path traversal e allowlist de diretórios. Não expor endpoints de execução arbitrária nem abrir acesso remoto sem autenticação e TLS.
- Serviço Git local, serviço GitHub API, adaptador Laravel Cloud, analisador de migrations, serviço de status e auditoria, controllers e componentes UI separados. Jobs/filas para operações demoradas, timeout, cancelamento seguro e feedback em tempo real por polling ou SSE; sem travar interface.
- Persistir configurações, preferências, associações projeto/repositório/ambiente, últimos estados confirmados e histórico de operações; marcar cache com data/hora, atualizar sob demanda e nunca apresentar cache antigo como “em tempo real”.
- Guardar segredos criptografados, mascarar logs, respeitar escopos mínimos e nunca versionar `.env`. Usar princípios de menor privilégio e idempotência nas ações críticas.
- Erros com orientação prática: “Git não encontrado”, “Pasta não é repositório”, “Token expirou”, “Você não tem permissão”, “O GitHub possui alterações mais recentes”, “Deploy falhou”, “Cloud não oferece essa consulta”.

## 9. Entregáveis e critérios de aceite

1. Inspecionar o HTML de referência e o ambiente local, propor plano breve e implementar a aplicação no diretório indicado, sem alterar os projetos `webe` e `webesolutions` durante a instalação.
2. Entregar interface completa nas quatro abas com estados vazios, carregamento, erros, responsividade, ícones, microanimações e ajuda contextual PT-BR.
3. Demonstrar com dados **reais**: detectar projetos, ler `git status`, mostrar diff, selecionar arquivos, commit, fetch, ahead/behind, push, branches e PRs via GitHub. Se faltar credencial, oferecer configuração e estado “não conectado”, nunca inventar sucesso.
4. Conectar Laravel Cloud **somente pelas integrações oficialmente verificadas**; quando não disponível, deixar módulo funcional com configuração e status honestos, sem simular deploy real.
5. Mostrar migrations alteradas no código e, separadamente, execução verificada ou não verificada por ambiente.
6. Garantir travas para push conflitante, troca de branch com alterações locais, produção sem homologação aprovada, diferença de SHA e reversão perigosa.
7. Incluir testes automatizados para leitura de Git, classificação de branch, comparações de SHA, validação de paths, proteção de secrets, divergência de remotos, fluxo de aprovação e tratamento de API indisponível.
8. Fornecer `README.md` em PT-BR com instalação Windows/XAMPP, configuração Git/GitHub/Cloud, comandos de execução, permissões necessárias, arquitetura, limitações reais e instruções de uso para iniciantes.
9. Implementar incrementalmente: **fase 1** leitura local segura + interface; **fase 2** commits e GitHub; **fase 3** homologação/produção e migrations; **fase 4** reversão, auditoria e refinamentos. Ao final de cada fase, rodar testes e explicar o que já é real e o que ainda depende de configuração.

**Orientação final:** priorize prevenção de erros, transparência sobre estados desconhecidos e facilidade de uso. Nunca transforme um clique simples em uma operação destrutiva escondida. O aplicativo deve ensinar o vocabulário profissional enquanto guia o usuário pelo fluxo seguro de publicação.
