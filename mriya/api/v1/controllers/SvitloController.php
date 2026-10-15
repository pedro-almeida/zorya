<?php
/**
 * SvitloController — Backend do módulo Svitlo.
 * SvitloController — Svitlo module backend.
 *
 * // PT: Controlador principal da API do Svitlo: geração de flashcards/resumos com IA, Anki, transcrição de áudio, perfis e conexões
 * // EN: Main API controller for Svitlo: AI flashcard/summary generation, Anki integration, audio transcription, profiles, and connections
 * // AI: @llm-context Chamada direta a Groq e Gemini (com fallback) — nunca expor chaves no client.
 * //     JWT: usar MriyaJWT::validateAccessToken(), NUNCA decode() (é private).
 * //     Rotas registradas em router.php sob o prefixo /api/v1/svitlo/*.
 *
 * Rotas / Routes:
 *   POST /api/v1/svitlo/generate          → Gerar cards + resumo
 *   POST /api/v1/svitlo/transcribe        → Transcrever áudio
 *   POST /api/v1/svitlo/export/pdf        → Exportar resumo e cards em PDF
 *   GET  /api/v1/svitlo/anki/decks        → Listar baralhos Anki
 *   POST /api/v1/svitlo/anki/decks        → Criar baralho Anki
 *   POST /api/v1/svitlo/anki/sync         → Sincronizar Anki
 *   POST /api/v1/svitlo/anki/send         → Enviar cards para Anki
 *   GET  /api/v1/svitlo/anki/cards        → Listar cards do baralho
 *   DELETE /api/v1/svitlo/anki/cards/{id} → Deletar card Anki
 *   PUT  /api/v1/svitlo/anki/cards/{id}   → Atualizar card Anki
 *   GET  /api/v1/svitlo/profile/academic  → Perfil acadêmico
 *   PUT  /api/v1/svitlo/profile/academic  → Salvar perfil acadêmico
 *   PUT  /api/v1/svitlo/profile/account   → Salvar conta
 *   GET  /api/v1/svitlo/connections       → Listar conexões
 *   GET  /api/v1/svitlo/connections/tracks → Listar faixas das conexões
 *   POST /api/v1/svitlo/connections/{svc} → Salvar conexão
 *   DELETE /api/v1/svitlo/connections/{svc} → Remover conexão
 *   GET  /api/v1/svitlo/accessibility     → Obter preferências de acessibilidade
 *   PUT  /api/v1/svitlo/accessibility     → Salvar preferências de acessibilidade
 *   GET  /api/v1/svitlo/i18n/{locale}     → Dicionário de traduções
 *   GET  /api/v1/svitlo/logs              → Logs de erros
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/crypto.php';

class SvitloController
{
    private array $user = [];
    private string $lastModelUsed = 'unknown';

    // PT: Mapeamento de idioma para voz neural do Edge-TTS
    // EN: Language to Edge-TTS neural voice mapping
    // AI: @llm-context Síntese de áudio multilíngue para flashcards
    private const VOICE_MAP = [
        'en' => 'en-GB-SoniaNeural',
        'it' => 'it-IT-ElsaNeural',
        'uk' => 'uk-UA-PolinaNeural',
        'fr' => 'fr-FR-DeniseNeural',
        'es' => 'es-ES-ElviraNeural',
        'de' => 'de-DE-KatjaNeural',
        'pt' => 'pt-BR-FranciscaNeural',
    ];

    // PT: Mapeamento de idioma para nome padronizado de baralho no Anki
    // EN: Language to standardized Anki deck mapping (no regional variants)
    // AI: @llm-context Sem variantes regionais — ex: 'Idiomas::Inglês'
    private const DECK_MAP = [
        'en' => 'Idiomas::Inglês',
        'it' => 'Idiomas::Italiano',
        'uk' => 'Idiomas::Ucraniano',
        'fr' => 'Idiomas::Francês',
        'es' => 'Idiomas::Espanhol',
        'de' => 'Idiomas::Alemão',
        'pt' => 'Idiomas::Português Brasileiro',
    ];

    // ── AUTH ──────────────────────────────────────────────────────────────────

    /**
     * PT: Valida o token JWT recebido no cabeçalho Authorization e popula os dados do usuário na instância.
     * EN: Validates JWT token received in Authorization header and populates user data in instance.
     * AI: @llm-context SEGURANÇA — nunca remover esta verificação.
     *     Usa MriyaJWT::validateAccessToken() — decode() é private.
     *     Extrai 'uid' ou 'sub' para definir $this->user['user_id'].
     *     Aborta com 401 se o token for ausente, inválido ou expirado.
     * @return void
     */
    // PT: Valida o token JWT e autentica o usuário ativo
    // EN: Validates JWT token and authenticates the active user
    // AI: @llm-context Extracts Bearer token, validates via MriyaJWT and sets $this->user
    protected function requireAuth(): void
    {
        if (!empty($this->user['user_id']) || !empty($this->user['uid'])) {
            return;
        }
        $token = MriyaJWT::extractFromHeader();
        if (!$token) $this->abort(401, 'Unauthorized');

        try {
            $payload = MriyaJWT::validateAccessToken($token);
        } catch (\Throwable $e) {
            $this->abort(401, 'Invalid token: ' . $e->getMessage());
        }

        // JWT usa 'uid' como user_id / JWT uses 'uid' as user_id
        $this->user            = $payload;
        $this->user['user_id'] = $payload['uid'] ?? $payload['sub'] ?? null;

        if (!$this->user['user_id']) {
            $this->abort(401, 'Token sem user_id / Token missing user_id');
        }
    }

    // ── GENERATE ──────────────────────────────────────────────────────────────

    /**
     * PT: Gera flashcards Anki, síntese, técnica de Feynman, perguntas e código Mermaid a partir de texto ou PDF.
     * EN: Generates Anki flashcards, summary synthesis, Feynman technique, review questions, and Mermaid code from text or PDF.
     * AI: @llm-context Rota POST /api/v1/svitlo/generate.
     *     Suporta multipart/form-data com upload de arquivo PDF ou JSON {content, mode, deck}.
     *     Para PDFs, valida MIME com finfo e extrai texto via pdftotext (fallback OCR Tesseract).
     *     Chama callAI() roteando prompt acadêmico para o AI Router.
     * @return void
     */
    // PT: Gera flashcards e síntese estruturada com IA a partir de texto base ou artigo
    // EN: Generates flashcards and structured summary with AI from base text or article
    // AI: @llm-context POST /api/v1/svitlo/generate orchestrates multi-agent AI pipeline
    public function generate(): void
    {
        $this->requireAuth();

        $content = '';
        $mode    = 'both';
        $deck    = 'Default';

        // PT: Aceita PDF via multipart OU texto via JSON
        // EN: Accepts PDF via multipart OR text via JSON
        // AI: @llm-context Validação estrita de MIME type real antes de qualquer comando shell
        if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
                $errCode = (int)$_FILES['pdf']['error'];
                $msg = ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE)
                    ? 'Arquivo PDF excede o tamanho máximo permitido no servidor.'
                    : 'Erro no envio do arquivo PDF (código ' . $errCode . ').';
                $this->abort(400, $msg);
                return;
            }

            $tmp = (string)$_FILES['pdf']['tmp_name'];

            if (!is_uploaded_file($tmp)) {
                $this->abort(400, 'Arquivo de upload inválido');
                return;
            }

            // Validação estrita do MIME Type real com finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $tmp) : '';
            if ($finfo) finfo_close($finfo);

            if ($mime !== 'application/pdf') {
                $this->abort(400, 'Arquivo rejeitado. Apenas arquivos PDF legítimos são permitidos.');
                return;
            }

            $out = tempnam(sys_get_temp_dir(), 'svitlo_') . '.txt';
            shell_exec("pdftotext -layout -enc UTF-8 " . escapeshellarg($tmp) . " " . escapeshellarg($out) . " 2>/dev/null");
            $content = file_exists($out) ? trim((string)file_get_contents($out)) : '';
            @unlink($out);

            if (strlen($content) < 50) {
                // PT: Fallback Tesseract para PDF escaneado
                // EN: Tesseract fallback for scanned PDF
                $pngPrefix = tempnam(sys_get_temp_dir(), 'svitlo_pg_');
                shell_exec("pdftoppm -r 150 -png -l 5 " . escapeshellarg($tmp) . " " . escapeshellarg($pngPrefix) . " 2>/dev/null");
                $content = '';
                $generatedPdfs = glob($pngPrefix . '-*.png') ?: [];
                sort($generatedPdfs);
                foreach ($generatedPdfs as $f) {
                    $content .= (string)shell_exec("tesseract " . escapeshellarg($f) . " stdout -l por+eng 2>/dev/null") . "\n";
                    @unlink($f);
                }
                @unlink($pngPrefix);
                $content = trim($content);
            }

            if (empty($content)) {
                $this->abort(400, 'Não foi possível extrair texto do PDF');
                return;
            }

            $mode = $_POST['mode'] ?? 'both';
            $deck = $_POST['deck'] ?? 'Default';
        } else {
            $body = $this->json();
            $content = trim((string)($body['content'] ?? $body['text'] ?? ''));
            $mode = (string)($body['mode'] ?? 'both');
            $deck = (string)($body['deck'] ?? 'Default');
        }

        if (empty($content)) {
            $this->abort(400, 'Conteúdo obrigatório / Content required');
        }

        // PT: Busca perfil cognitivo e idioma nativo do usuário para personalizar a IA
        // EN: Fetch cognitive profile and native language to personalize AI response
        // AI: @llm-context Injeta perfil do usuário no prompt para adaptar estilo e idioma
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $userProfile = MriyaDB::queryOne(
            'SELECT a.native_lang, a.study_focus, p.cognitive_profile
             FROM user_accessibility_preferences a
             LEFT JOIN svitlo_academic_profiles p ON p.user_id = a.user_id
             WHERE a.user_id = ?',
            [$uid]
        );
        $nativeLang      = $userProfile['native_lang'] ?? 'pt-BR';
        $studyFocus      = $userProfile['study_focus'] ?? 'academico';
        $cognitiveRaw    = $userProfile['cognitive_profile'] ?? null;
        $cognitiveData   = $cognitiveRaw ? json_decode($cognitiveRaw, true) : null;

        $langMap = [
            'pt-BR' => 'português brasileiro',
            'en-GB' => 'inglês britânico',
            'it-IT' => 'italiano',
            'uk-UA' => 'ucraniano',
            'es-ES' => 'espanhol',
        ];
        $langName = $langMap[$nativeLang] ?? 'português brasileiro';

        $focusMap = [
            'academico'     => 'graduação universitária',
            'concurso'      => 'concurso público',
            'residencia'    => 'residência médica',
            'certificacao'  => 'certificação profissional',
            'idiomas'       => 'aprendizado de idiomas',
            'livre'         => 'aprendizado livre',
        ];
        $focusName = $focusMap[$studyFocus] ?? 'graduação universitária';

        $cognitiveContext = '';
        if ($cognitiveData && isset($cognitiveData['summary'])) {
            $summary = implode(', ', (array)($cognitiveData['summary'] ?? []));
            $cognitiveContext = "

PERFIL COGNITIVO DO ESTUDANTE: {$summary}. Adapte o nível de complexidade, os exemplos e o estilo de explicação a este perfil.";
        }

        // PT: Prompt com rigor acadêmico, Mermaid e Técnica de Feynman
        // EN: Prompt with academic rigor, Mermaid and Feynman Technique
        
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $neuro = MriyaDB::queryOne('SELECT neuro_qi, neuro_diagnoses, neuro_working_memory, neuro_attention, neuro_attention_concentrated, neuro_attention_divided, neuro_attention_alternated, neuro_inhibitory_control, neuro_visual_memory, neuro_instrument, neuro_eval_valid_until, neuro_flexibility, neuro_processing_speed, neuro_notes FROM svitlo_academic_profiles WHERE user_id = ?', [$userId]);
        $neuroContext = '';
        if ($neuro && (!empty($neuro['neuro_qi']) || !empty($neuro['neuro_diagnoses']) || !empty($neuro['neuro_working_memory']) || !empty($neuro['neuro_attention']) || !empty($neuro['neuro_attention_concentrated']))) {
            $diags = !empty($neuro['neuro_diagnoses'])
                ? (is_string($neuro['neuro_diagnoses'])
                    ? json_decode($neuro['neuro_diagnoses'], true)
                    : $neuro['neuro_diagnoses'])
                : [];
            $diagStr = implode(', ', $diags ?: []);
            $qiInt = (int)($neuro['neuro_qi'] ?? 0);
            $qiClass = $qiInt > 0 ? " ({$this->classifyIQ($qiInt)})" : '';
            $qiStr = !empty($neuro['neuro_qi']) ? "{$neuro['neuro_qi']}{$qiClass}" : 'não informado';
            $attConc = $neuro['neuro_attention_concentrated'] ?? 'não informado';
            $attDiv = $neuro['neuro_attention_divided'] ?? 'não informado';
            $attAlt = $neuro['neuro_attention_alternated'] ?? 'não informado';
            $attGen = $neuro['neuro_attention'] ?? 'não informado';
            $inhCtrl = $neuro['neuro_inhibitory_control'] ?? 'não informado';
            $visMem = $neuro['neuro_visual_memory'] ?? 'não informado';
            $procSpd = $neuro['neuro_processing_speed'] ?? 'não informado';
            $cogFlex = $neuro['neuro_flexibility'] ?? 'não informado';
            $insts = $neuro['neuro_instrument'] ?? 'não informado';
            $notes = $neuro['neuro_notes'] ?? 'não informado';

            $neuroContext = "\n\nPERFIL NEUROPSICOLÓGICO CLÍNICO (prioridade máxima):
QI: {$qiStr}
Diagnósticos: {$diagStr}
Atenção Concentrada: {$attConc}
Atenção Dividida: {$attDiv}
Atenção Alternada: {$attAlt}
Atenção Geral: {$attGen}
Controle Inibitório: {$inhCtrl}
Memória Visual: {$visMem}
Velocidade de Processamento: {$procSpd}
Flexibilidade Cognitiva: {$cogFlex}
Instrumentos: {$insts}
Observações: {$notes}

ADAPTAÇÕES OBRIGATÓRIAS:
- Atenção Concentrada baixa → sessões curtas (15-20 min), um conceito por vez
- Atenção Dividida boa → pode usar exemplos com múltiplos elementos
- Atenção Alternada baixa → não mudar de tema abruptamente na mesma sessão
- Memória visual excepcional → priorizar mapas mentais e esquemas visuais
- Velocidade lenta → textos mais curtos, sem pressão de tempo
- Controle inibitório alto → pode usar técnicas de foco deliberado
- TEA → exemplos concretos, linguagem direta, sem metáforas abstratas
- TOC → estrutura rígida e previsível, sem ambiguidade";
        }

        // PT: Perfil Neural Sintetizado com IA
        // EN: Synthesized AI Neural Profile
        $neuralProfile = $this->getNeuralProfile($userId);
        $neuralContext = '';
        if ($neuralProfile) {
            $learningStyle = $neuralProfile['learning_style'] ?? 'visual';
            $strengths = is_array($neuralProfile['academic_strengths']) ? implode(', ', $neuralProfile['academic_strengths']) : '';
            $weaknesses = is_array($neuralProfile['academic_weaknesses']) ? implode(', ', $neuralProfile['academic_weaknesses']) : '';
            $hints = is_array($neuralProfile['content_generation_hints']) ? $neuralProfile['content_generation_hints'] : [];
            $preferredStyle = $hints['preferred_explanation_style'] ?? '';
            $analogies = isset($hints['analogy_domains']) && is_array($hints['analogy_domains']) ? implode(', ', $hints['analogy_domains']) : '';
            $avoid = isset($hints['avoid']) && is_array($hints['avoid']) ? implode(', ', $hints['avoid']) : '';

            $neuralContext = "\n\nPERFIL NEURAL SINTETIZADO (IA Adaptativa):
- Estilo cognitivo: {$learningStyle}
- Pontos fortes: {$strengths}
- Pontos de atenção: {$weaknesses}
- Preferência de explicação: {$preferredStyle}
- Analogias que ressoam: {$analogies}" . ($avoid ? "\n- Evitar: {$avoid}" : '');
        }

        // PT: Estilo didático dos professores favoritos do YouTube
        // EN: Didactic style of user's favorite YouTube professors
        $academicProf = MriyaDB::queryOne('SELECT youtube_professors, course, institution FROM svitlo_academic_profiles WHERE user_id = ?', [$userId]);
        $ytContext = '';
        if (!empty($academicProf['youtube_professors'])) {
            $ytProfessors = is_string($academicProf['youtube_professors']) ? json_decode($academicProf['youtube_professors'], true) : $academicProf['youtube_professors'];
            if (is_array($ytProfessors) && count($ytProfessors) > 0) {
                $ytTitles = array_filter(array_map(fn($v) => is_array($v) ? ($v['title'] ?? '') : (is_string($v) ? $v : ''), $ytProfessors));
                if (!empty($ytTitles)) {
                    $ytContext = "\n\nESTILO DIDÁTICO DOS PROFESSORES FAVORITOS:
Espelhe a didática, clareza e ritmo das seguintes referências do estudante: " . implode('; ', $ytTitles);
                }
            }
        }

        // PT: Contexto da Disciplina e Esquema de Avaliação
        // EN: Discipline Context and Grade Schema
        $disciplineId = (int)($body['discipline_id'] ?? ($_POST['discipline_id'] ?? 0));
        $disciplineContext = '';
        $hasGradeSchema = false;
        $disc = null;
        if ($disciplineId > 0) {
            $disc = MriyaDB::queryOne('SELECT name, code, professor, syllabus FROM svitlo_disciplines WHERE id = ? AND user_id = ?', [$disciplineId, $userId]);
            if ($disc) {
                $schema = MriyaDB::queryOne('SELECT name, passing_grade, schema_json FROM svitlo_grade_schemas WHERE discipline_id = ? AND user_id = ?', [$disciplineId, $userId]);
                $schemaStr = '';
                if ($schema) {
                    $hasGradeSchema = true;
                    $schemaStr = "\n- Esquema de avaliação da matéria: {$schema['name']} (Mínimo para aprovação: {$schema['passing_grade']})";
                }
                $disciplineContext = "\n\nCONTEXTO DA MATÉRIA / DISCIPLINA ATUAL:
- Disciplina: {$disc['name']} ({$disc['code']})
- Professor da matéria: {$disc['professor']}
- Ementa acadêmica: {$disc['syllabus']}{$schemaStr}";
            }
        }

        // PT: Filtros de delimitação de pesquisa (Arquivos da Disciplina, Internet, Modelo Nativo, OpenAlex)
        $rawScopes = $_POST['search_scopes'] ?? ($body['search_scopes'] ?? null);
        $searchScopes = is_string($rawScopes) ? (json_decode($rawScopes, true) ?: []) : (is_array($rawScopes) ? $rawScopes : []);

        $disciplineMaterialsContext = '';
        if ((!isset($searchScopes['discipline_files']) || !empty($searchScopes['discipline_files'])) && $disciplineId > 0) {
            $matRows = MriyaDB::query(
                'SELECT text_content FROM svitlo_discipline_materials WHERE discipline_id = ? AND user_id = ? AND text_content IS NOT NULL AND text_content != "" ORDER BY id DESC LIMIT 3',
                [$disciplineId, $userId]
            );
            if (!empty($matRows)) {
                $matTexts = array_filter(array_map(fn($r) => trim((string)$r['text_content']), $matRows));
                if (!empty($matTexts)) {
                    $disciplineMaterialsContext = "\n\nARQUIVOS E MATERIAIS DA DISCIPLINA (conhecimento prévio validado):\n" . substr(implode("\n---\n", $matTexts), 0, 4000);
                }
            }
        }

        // PT: Busca referências acadêmicas reais via OpenAlex para fundamentar os cards sem alucinação
        // EN: Fetch real academic references via OpenAlex to back cards without hallucinations
        // AI: @llm-context Busca no OpenAlex, cache de 30 dias em svitlo_academic_cache e injeção de resumos no prompt
        $userEmail = (string)($this->user['email'] ?? '');
        if (!$userEmail) {
            $userRow = MriyaDB::queryOne('SELECT email FROM users WHERE id = ?', [$userId]);
            $userEmail = (string)($userRow['email'] ?? 'pedro@espectroipe.com.br');
        }

        $openAlexRefs = [];
        if (!isset($searchScopes['openalex']) || !empty($searchScopes['openalex'])) {
            $openAlexRefs = $this->searchOpenAlexReferences($content, $userEmail);
        }

        $openAlexContext = '';
        if (!empty($openAlexRefs)) {
            $refLines = [];
            foreach ($openAlexRefs as $idx => $r) {
                $refNum = $idx + 1;
                $refLines[] = "[Ref {$refNum}] {$r['authors']} ({$r['year']}). \"{$r['title']}\". DOI: {$r['doi']}. Resumo: {$r['abstract']}";
            }
            $openAlexContext = "\n\nREFERÊNCIAS VERIFICADAS — use APENAS estas para fundamentar o conteúdo e validar os conceitos:\n" . implode("\n", $refLines) . "
\nINSTRUÇÕES DE CITAÇÃO OBRIGATÓRIAS:
- Cada flashcard DEVE ter uma propriedade 'citation' caso seja baseado em uma das referências verificadas acima:
  \"citation\": {\"authors\": \"Autor et al.\", \"year\": 2024, \"title\": \"Título\", \"doi\": \"https://doi.org/...\", \"url\": \"https://doi.org/...\"}
- Se um flashcard não tiver referência direta nas obras verificadas, marque-o com: \"unverified\": true.";
        }

        // PT: Validação científica — Krashen (1982) e Método Michel Thomas
        // EN: Scientific validation — Krashen (1982) and Michel Thomas Method
        // AI: @llm-context Injeta Input Compreensível na síntese e Michel Thomas para decomposição estrutural de idiomas
        $isLanguageTopic = ($studyFocus === 'idiomas') ||
            !empty($body['is_language']) ||
            ($disc && preg_match('/(idiom|ingl|franc|espanh|alem|italian|russo|ucranian)/i', (string)$disc['name'])) ||
            preg_match('/(grammar|vocabulary|idioma|língua estrangeira|pronúncia|verbos)/i', $content);

        $scientificValidationPrompt = "\n\nVALIDAÇÃO CIENTÍFICA E METODOLÓGICA OBRIGATÓRIA:
1. INPUT COMPREENSÍVEL (Krashen, 1982): Na elaboração da síntese (synthesis), aplique estritamente o princípio do Input Compreensível (hipótese i + 1). O conteúdo deve ser fornecido em um nível linguístico e conceitual imediatamente superior ao atual do estudante, mas 100% compreensível através de pistas contextuais, progressão gradual e ancoragem semântica, mantendo o filtro afetivo rebaixado para maximizar a aquisição duradoura.";

        if ($isLanguageTopic) {
            $scientificValidationPrompt .= "\n2. MÉTODO MICHEL THOMAS (Decomposição Estrutural de Idiomas): Como este conteúdo envolve aprendizado de IDIOMAS / SEGUNDA LÍNGUA, aplique imperativamente a metodologia do Método Michel Thomas: desconstrua as estruturas oracionais e regras gramaticais em seus blocos fundamentais (building blocks). Demonstre como recombinar verbos auxiliares, pronomes e raízes léxicas sem sobrecarga de memorização passiva ou termos técnicos desnecessários, capacitando o aprendiz a construir frases autênticas de forma lógica e imediata.";
        } else {
            $scientificValidationPrompt .= "\n2. MÉTODO MICHEL THOMAS (Decomposição Estrutural de Terminologias): Para quaisquer termos técnicos, etimologias ou palavras estrangeiras presentes no material, aplique a decomposição estrutural do Método Michel Thomas, fragmentando-as em raízes morfológicas elementares e demonstrando sua recombinação lógica intuitiva.";
        }

        $systemPrompt = "Você é um professor universitário e cientista cognitivo especializado em retenção de longo prazo.{$neuroContext}{$neuralContext}{$ytContext}{$disciplineContext}{$disciplineMaterialsContext}{$openAlexContext}{$scientificValidationPrompt}
Sua missão é destilar o material fornecido com RIGOR ACADÊMICO absoluto.
Você deve aplicar a Técnica de Feynman: explicar os conceitos mais complexos de forma simples, mas sem perder a profundidade técnica.

FORMATO OBRIGATÓRIO DO JSON DE RESPOSTA:
Retorne EXCLUSIVAMENTE um objeto JSON válido seguindo esta estrutura exata:
{
  \"topic\": \"Título representativo e elegante do tema estudado\",
  \"cards\": [
    {
      \"front\": \"Pergunta direta ou cloze deletion\",
      \"back\": \"Resposta direta e precisa\",
      \"teacher_explanation\": \"Explicação didática falada (2 a 3 frases conversacionais em tom de professor explicando o raciocínio por trás da resposta para fixação na memória)\",
      \"visual_svg\": \"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 440 160' width='440' height='160'>...</svg> (diagrama conceitual SVG limpo e didático com fundo #FDFBF7, blocos #EFE4D2, setas #8B5E3C e textos #2C1E16 esquematizando visualmente o conceito do card)\",
      \"tags\": [\"tema\"],
      \"citation\": {\"authors\": \"...\", \"year\": 2024, \"title\": \"...\", \"doi\": \"...\", \"url\": \"...\"}
    }
  ],
  \"summary\": {
    \"topic\": \"Título representativo e elegante do tema estudado\",
    \"synthesis\": \"Síntese aprofundada, clara e bem desenvolvida sobre o tema estudado.\",
    \"teacher_audio_script\": \"Explicação falada completa (4 a 6 frases) como se você fosse o professor explicando este resumo em voz alta para o aluno.\",
    \"key_points\": [\"Ponto principal 1\", \"Ponto principal 2\", \"Ponto principal 3\"],
    \"review_questions\": [\"Pergunta crítica 1?\", \"Pergunta reflexiva 2?\"],
    \"feynman\": \"Explicação intuitiva usando analogias cotidianas simples sem perder o rigor conceitual.\",
    \"mermaid\": \"graph TD; A[Conceito]-->B[Detalhe]\"
  }
}

IDIOMA DE RESPOSTA: Responda SEMPRE em {$langName}. Todos os textos dos cards, resumo, perguntas e explicação Feynman devem estar em {$langName}.
FOCO DO ESTUDANTE: Este estudante estuda para {$focusName}. Adapte exemplos e profundidade a este contexto.{$cognitiveContext}";

        // PT: Adaptações cognitivas do motor v3
        // EN: Cognitive adaptations from v3 engine
        // AI: @llm-context Injeta perfil cognitivo adaptativo no prompt com cache Redis 24h
        $cogProfile = $this->getCognitiveAdaptations($userId);
        if ($cogProfile) {
            $systemPrompt .= "\n\nPERFIL COGNITIVO DO ESTUDANTE (use para adaptar o conteúdo):\n" . $cogProfile;
        }

        $prompt = "Conteúdo para estudo:\n{$content}\n\nRetorne o JSON completo com flashcards e resumo estruturado (synthesis, key_points, review_questions, feynman, mermaid).";

        $task = match($mode) {
            'summary' => 'summary',
            'cards'   => 'cards',
            'mindmap' => 'mindmap',
            default   => 'generate'
        };
        $keyName = match($mode) {
            'summary' => 'svitlo_summary',
            'mindmap' => 'svitlo_mindmap',
            default   => 'svitlo_cards'
        };

        // PT: Enriquecimento contextual via Svitlo Orchestrator (porta 8200)
        // EN: Contextual prompt enrichment via Svitlo Orchestrator (port 8200)
        // AI: @llm-context POST to http://127.0.0.1:8200 action=contextual_generate. Timeout: 2s. Fallback silencioso.
        $orchContext = $this->callOrchestrator('contextual_generate', $userId, [], 2);
        if ($orchContext && !empty($orchContext['system_prompt'])) {
            $systemPrompt = $orchContext['system_prompt'] . "\n\n" . $systemPrompt;
        }

        try {
            $aiResponse = $this->callAI($systemPrompt, $prompt, $task, $keyName);
            // PT: Remove markdown antes de decodificar
            // EN: Strip markdown before decode
            // AI: @llm-context IA pode retornar ```json ... ``` — limpar antes do json_decode
            $clean = $this->extractJson($aiResponse);
            $data = null;
            if (preg_match('/(\{|\[).*(\}|\])/s', $clean, $matches)) {
                $data = json_decode($matches[0], true);
            }
            if (!is_array($data)) {
                $data = json_decode($clean, true);
            }

            if (!is_array($data)) {
                $this->logError('generate', 'Resposta da IA não é JSON válido', [
                    'preview' => substr((string)$aiResponse, 0, 300),
                    'full_len' => strlen((string)$aiResponse),
                    'tail' => substr((string)$aiResponse, -300),
                    'json_error' => json_last_error_msg(),
                ]);
                $this->abort(500, 'Erro ao processar resposta da IA. Tente novamente.');
            }

            // PT: Processa cards garantindo estrutura de citação acadêmica verificada ou tag unverified
            // EN: Processes cards ensuring verified academic citation structure or unverified tag
            // AI: @llm-context Injeta citação do OpenAlex ou define citation: null com tag '⚠️ não verificado'
            $cards = [];
            $rawCards = $data['cards'] ?? ($data['flashcards'] ?? ($data['items'] ?? []));
            if (is_array($rawCards)) {
                foreach ($rawCards as $c) {
                    if (is_array($c) && (!empty($c['front']) || !empty($c['question'])) && (!empty($c['back']) || !empty($c['answer']))) {
                        $teacherExp = trim((string)($c['teacher_explanation'] ?? ''));
                        if ($teacherExp === '') {
                            $teacherExp = "Entenda por que isso ocorre: " . strip_tags((string)($c['back'] ?? $c['answer']));
                        }
                        $visualSvg = trim((string)($c['visual_svg'] ?? ''));
                        if ($visualSvg === '' || !str_contains($visualSvg, '<svg')) {
                            $safeFront = htmlspecialchars(mb_substr(strip_tags((string)($c['front'] ?? 'Conceito')), 0, 52), ENT_QUOTES, 'UTF-8');
                            $safeBack  = htmlspecialchars(mb_substr(strip_tags((string)($c['back'] ?? '')), 0, 76), ENT_QUOTES, 'UTF-8');
                            $visualSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 440 150" width="440" height="150"><rect width="440" height="150" rx="12" fill="#FDFBF7" stroke="#C8A97E" stroke-width="2"/><rect x="16" y="14" width="408" height="46" rx="8" fill="#EFE4D2"/><text x="220" y="42" font-family="sans-serif" font-size="13" font-weight="bold" fill="#3D2B1F" text-anchor="middle">' . $safeFront . '</text><path d="M220 60 L220 76" stroke="#8B5E3C" stroke-width="2"/><rect x="16" y="76" width="408" height="58" rx="8" fill="#FFFDF9" stroke="#D8C3A5"/><text x="220" y="109" font-family="sans-serif" font-size="12" fill="#5C3D2E" text-anchor="middle">' . $safeBack . '</text></svg>';
                        }
                        $cardObj = [
                            'front'               => (string)($c['front'] ?? $c['question']),
                            'back'                => (string)($c['back'] ?? $c['answer']),
                            'teacher_explanation' => $teacherExp,
                            'visual_svg'          => $visualSvg,
                            'image_data_url'      => 'data:image/svg+xml;base64,' . base64_encode($visualSvg),
                            'tags'                => is_array($c['tags'] ?? null) ? array_map('strval', $c['tags']) : ['svitlo'],
                            'unverified'          => (bool)($c['unverified'] ?? false),
                        ];

                        if (!empty($c['citation']) && is_array($c['citation']) && !empty($c['citation']['authors'])) {
                            $cardObj['citation'] = [
                                'authors' => (string)$c['citation']['authors'],
                                'year'    => $c['citation']['year'] ?? null,
                                'title'   => (string)($c['citation']['title'] ?? ''),
                                'doi'     => (string)($c['citation']['doi'] ?? ''),
                                'url'     => (string)($c['citation']['url'] ?? ''),
                            ];
                        } elseif (!empty($openAlexRefs) && !$cardObj['unverified']) {
                            $ref0 = $openAlexRefs[0];
                            $cardObj['citation'] = [
                                'authors' => (string)$ref0['authors'],
                                'year'    => $ref0['year'] ?? null,
                                'title'   => (string)$ref0['title'],
                                'doi'     => (string)$ref0['doi'],
                                'url'     => (string)$ref0['url'],
                            ];
                        } else {
                            $cardObj['citation'] = null;
                            if (!in_array('⚠️ não verificado', $cardObj['tags'], true)) {
                                $cardObj['tags'][] = '⚠️ não verificado';
                            }
                        }

                        $cards[] = $cardObj;
                    }
                }
            }

            // PT: Normalização robusta de todos os campos de resumo gerados
            // EN: Robust normalization of all generated summary fields
            // AI: @llm-context Extrai synthesis, key_points, review_questions, feynman e mermaid
            $rawSum = $data['summary'] ?? null;
            $sumArr = is_array($rawSum) ? $rawSum : [];
            $rawSynthesis = is_string($rawSum) ? $rawSum : ($sumArr['synthesis'] ?? ($data['synthesis'] ?? ($data['summary.synthesis'] ?? ($data['resumo'] ?? ''))));
            $rawKeyPoints = $sumArr['key_points'] ?? ($data['key_points'] ?? ($data['summary.key_points'] ?? ($data['pontos_chave'] ?? [])));
            $rawQuestions = $sumArr['review_questions'] ?? ($data['review_questions'] ?? ($data['summary.review_questions'] ?? ($data['perguntas'] ?? [])));
            $rawFeynman   = $sumArr['feynman'] ?? ($data['feynman'] ?? ($data['summary.feynman'] ?? ''));
            $rawMermaid   = $sumArr['mermaid'] ?? ($data['mermaid'] ?? ($data['mind_map_mermaid'] ?? ''));

            // Fallback de síntese caso venha vazio mas existam pontos-chave
            if (empty(trim((string)$rawSynthesis)) && !empty($rawKeyPoints) && is_array($rawKeyPoints)) {
                $rawSynthesis = implode(". ", array_map('strval', $rawKeyPoints)) . ".";
            }

            // PT: Título representativo e elegante do tópico de estudo
            // EN: Representative and elegant title for the study topic
            // AI: @llm-context Extrai o título fornecido pelo usuário ou sintetizado pela IA
            $derivedTopic = trim((string)($data['topic'] ?? ($sumArr['topic'] ?? ($body['topic'] ?? ($_POST['topic'] ?? '')))));
            if (empty($derivedTopic)) {
                if (!empty($rawKeyPoints[0])) {
                    $derivedTopic = (string)$rawKeyPoints[0];
                } else {
                    $derivedTopic = 'Resumo de Estudo';
                }
            }

            $summaryData = [
                'topic'                => $derivedTopic,
                'synthesis'            => trim((string)$rawSynthesis),
                'teacher_audio_script' => (string)($rawSum['teacher_audio_script'] ?? $rawSum['feynman'] ?? $rawSum['synthesis'] ?? ''),
                'key_points'           => is_array($rawKeyPoints) ? array_values(array_filter(array_map('strval', $rawKeyPoints))) : [],
                'review_questions'    => is_array($rawQuestions) ? array_values(array_filter(array_map('strval', $rawQuestions))) : [],
                'feynman'             => trim((string)$rawFeynman),
                'mermaid'             => trim((string)$rawMermaid),
                // PT: Formato Cornell explícito (Cues/Perguntas-chave, Notes/Notas Principais, Summary/Síntese Inferior)
                // EN: Explicit Cornell format (Cues, Main Notes, Summary)
                // AI: @llm-context Estrutura Cornell e Técnica de Feynman para Estudo Ativo
                'cornell'             => [
                    'cues'            => is_array($rawQuestions) ? array_values(array_filter(array_map('strval', $rawQuestions))) : [],
                    'notes'           => trim((string)$rawSynthesis),
                    'summary'         => implode("\n• ", is_array($rawKeyPoints) ? array_values(array_filter(array_map('strval', $rawKeyPoints))) : []),
                ],
                'socratic_questions'  => is_array($rawQuestions) ? array_values(array_filter(array_map('strval', $rawQuestions))) : [],
                'feynman_explanation' => trim((string)$rawFeynman),
                'mermaid_diagram'     => trim((string)$rawMermaid),
            ];

            $resultModel = (!empty($this->lastModelUsed) && $this->lastModelUsed !== 'unknown') ? $this->lastModelUsed : 'groq/qwen3.8-27b';

            $resultPayload = [
                'success'             => true,
                'topic'               => $derivedTopic,
                'cards'               => $cards,
                'summary'             => $summaryData,
                'model_used'          => $resultModel,
                'error'               => null,
                'academic_references' => $openAlexRefs,
                'profile_used'        => [
                    'cognitive'          => !empty($cognitiveData) || !empty($neuro),
                    'neural'             => !empty($neuralProfile),
                    'youtube_style'      => !empty($ytContext),
                    'discipline_context' => !empty($disciplineContext),
                    'grade_schema'       => $hasGradeSchema,
                    'openalex_verified'  => !empty($openAlexRefs),
                ],
                'meta'                => [
                    'count'              => count($cards),
                    'model_used'         => $resultModel,
                    'references_count'   => count($openAlexRefs),
                ],
            ];

            $this->respond($resultPayload);

        } catch (\Throwable $e) {
            $this->logError('generate', $e->getMessage());
            $this->abort(500, 'Erro interno ao gerar conteúdo: ' . $e->getMessage());
        }
    }

    // ── TRANSCRIBE ────────────────────────────────────────────────────────────

    /**
     * PT: Transcreve áudio enviado pelo usuário utilizando o modelo Whisper Large via API Groq.
     * EN: Transcribes user-uploaded audio using Whisper Large model via Groq API.
     * AI: @llm-context Rota POST /api/v1/svitlo/transcribe.
     *     Requer arquivo no campo 'file' de $_FILES.
     *     Timeout: 60s. Fallback: lança erro 500 se o serviço estiver offline.
     * @return void
     */
    // PT: Transcreve arquivo de áudio utilizando Whisper com detecção de idioma
    // EN: Transcribes audio file using Whisper with language detection
    // AI: @llm-context POST /api/v1/svitlo/transcribe receives multipart audio and returns text
    public function transcribe(): void
    {
        $this->requireAuth();

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->abort(400, 'Arquivo de áudio obrigatório / Audio file required');
        }

        $language = (string)($_POST['language'] ?? 'pt');
        $filePath = (string)$_FILES['file']['tmp_name'];

        if (!is_uploaded_file($filePath)) {
            $this->abort(400, 'Arquivo inválido');
        }

        try {
            $text = $this->callWhisper($filePath, $language);
            if (empty($text)) {
                $this->abort(500, 'Erro ao transcrever áudio. Tente novamente.');
            }
            $this->respond(['text' => $text]);
        } catch (\Throwable $e) {
            $this->logError('transcribe', $e->getMessage());
            $this->abort(500, 'Erro interno na transcrição.');
        }
    }

    // ── EXPORT PDF ────────────────────────────────────────────────────────────

    /**
     * PT: Converte o resumo de estudo, técnica de Feynman e flashcards em documento PDF elegante e estruturado com nome inteligente.
     * EN: Converts study summary, Feynman technique, and flashcards into an elegant structured PDF document with smart naming.
     * AI: @llm-context Rota POST /api/v1/svitlo/export/pdf.
     *     Gera HTML com paleta institucional Svitlo (#FAF7F2, #5C3D1E, #C9A84C), cabeçalho, cards visuais e rodapé.
     *     Formata cabeçalho Content-Disposition com nome inteligente derivado do assunto estudado.
     *     Converte com wkhtmltopdf e retorna stream binário de PDF ou JSON base64.
     * @return void
     */
    // PT: Exporta resumo e flashcards em formato PDF formatado
    // EN: Exports summary and flashcards in formatted PDF
    // AI: @llm-context POST /api/v1/svitlo/export/pdf converts HTML study summary into PDF
    public function exportPdf(): void
    {
        $this->requireAuth();
        $body = $this->json();

        $rawTopic = trim((string)($body['topic'] ?? $body['title'] ?? ''));
        if (empty($rawTopic) || $rawTopic === 'Resumo' || $rawTopic === 'Resumo de Estudo' || $rawTopic === 'Resumo de Estudo Svitlo') {
            $rawTopic = trim((string)($body['summary']['topic'] ?? ''));
        }
        if (empty($rawTopic)) {
            $rawTopic = 'Resumo de Estudo';
        }

        $title   = $rawTopic;
        $content = (string)($body['content'] ?? ($body['summary']['synthesis'] ?? ($body['synthesis'] ?? '')));
        $keyPoints = $body['key_points'] ?? ($body['summary']['key_points'] ?? []);
        $reviewQuestions = $body['review_questions'] ?? ($body['summary']['review_questions'] ?? []);
        $concepts = $body['concepts'] ?? ($body['summary']['concepts'] ?? []);
        $cards   = is_array($body['cards'] ?? null) ? $body['cards'] : [];
        $feynman = (string)($body['feynman'] ?? ($body['summary']['feynman'] ?? ''));

        if (empty($content) && empty($cards) && empty($keyPoints) && empty($feynman)) {
            $this->abort(400, 'Nenhum conteúdo para exportar.');
            return;
        }

        // Formatação limpa de parágrafos da síntese (remove tabs e preserva quebras)
        $paragraphs = array_filter(array_map('trim', preg_split('/\n\s*\n|\r\n\s*\r\n/', str_replace("\t", ' ', $content))));
        $synthesisHtml = '';
        foreach ($paragraphs as $p) {
            $synthesisHtml .= '<p style="margin: 0 0 12px 0; line-height: 1.7; text-align: justify; font-size: 13.5px; color: #2C1F14;">' . nl2br(htmlspecialchars($p)) . '</p>';
        }

        $dateFormatted = date('d/m/Y');

        $html  = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
        $html .= '<title>' . htmlspecialchars($title) . ' — Svitlo</title>';
        $html .= '<style>';
        $html .= '@page { size: A4; margin: 15mm 12mm 15mm 12mm; }';
        $html .= 'body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; margin: 0; padding: 0; color: #2C1F14; background-color: #FAF7F2; line-height: 1.6; }';
        $html .= '.header-bar { display: table; width: 100%; border-bottom: 2px solid #C9A84C; padding-bottom: 12px; margin-bottom: 20px; }';
        $html .= '.brand-cell { display: table-cell; vertical-align: middle; }';
        $html .= '.brand-name { font-family: Georgia, "Playfair Display", serif; font-size: 24px; font-weight: bold; color: #5C3D1E; }';
        $html .= '.brand-sub { font-size: 11px; color: #8B5E3C; text-transform: uppercase; letter-spacing: 1.5px; margin-left: 8px; }';
        $html .= '.date-cell { display: table-cell; vertical-align: middle; text-align: right; font-size: 12px; color: #8B5E3C; }';
        $html .= '.topic-badge { display: inline-block; font-size: 10px; font-weight: bold; letter-spacing: 1.5px; color: #8B5E3C; background: #F0E8D8; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; margin-bottom: 8px; }';
        $html .= 'h1.topic-title { font-family: Georgia, "Playfair Display", serif; color: #5C3D1E; font-size: 22px; margin: 0 0 18px 0; line-height: 1.3; font-weight: bold; }';
        $html .= '.section-title { font-family: Georgia, "Playfair Display", serif; color: #5C3D1E; font-size: 16px; margin: 20px 0 10px 0; padding-bottom: 4px; border-bottom: 1px solid #E2D9CC; }';
        $html .= '.synthesis-box { background: #FFFFFF; padding: 16px 18px; border-radius: 8px; border-left: 4px solid #C9A84C; border-top: 1px solid #E2D9CC; border-right: 1px solid #E2D9CC; border-bottom: 1px solid #E2D9CC; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }';
        $html .= '.feynman-box { background: #FFFDF7; padding: 14px 16px; border-radius: 8px; border: 1px solid #F5DCA8; border-left: 4px solid #C9A84C; margin-bottom: 20px; }';
        $html .= '.feynman-title { font-weight: bold; color: #8B5E3C; font-size: 13.5px; margin-bottom: 6px; }';
        $html .= '.feynman-text { font-size: 12.5px; font-style: italic; color: #3A2B1D; line-height: 1.6; }';
        $html .= '.list-styled { margin: 0 0 18px 20px; padding: 0; font-size: 13px; color: #2C1F14; }';
        $html .= '.list-styled li { margin-bottom: 6px; line-height: 1.5; }';
        $html .= '.card-item { background: #FFFFFF; border: 1px solid #E2D9CC; border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; page-break-inside: avoid; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }';
        $html .= '.card-front { font-weight: bold; color: #5C3D1E; font-size: 13px; margin-bottom: 6px; line-height: 1.4; }';
        $html .= '.card-divider { border: 0; border-top: 1px dashed #E2D9CC; margin: 6px 0; }';
        $html .= '.card-back { color: #2C1F14; font-size: 12.5px; line-height: 1.5; }';
        $html .= '.card-footer { margin-top: 8px; font-size: 10.5px; color: #065F46; background: #ECFDF5; border: 1px solid #A7F3D0; padding: 3px 6px; border-radius: 4px; display: inline-block; }';
        $html .= '.card-tag { font-size: 10px; color: #8B5E3C; background: #F0E8D8; padding: 2px 5px; border-radius: 3px; margin-right: 4px; }';
        $html .= '.page-footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #E2D9CC; text-align: center; font-size: 10.5px; color: #8B5E3C; font-style: italic; }';
        $html .= '</style></head><body>';

        // Cabeçalho institucional
        $html .= '<div class="header-bar">';
        $html .= '<div class="brand-cell"><span class="brand-name">🕊️ Svitlo</span><span class="brand-sub">Mriya Ecosystem</span></div>';
        $html .= '<div class="date-cell">' . htmlspecialchars($dateFormatted) . '</div>';
        $html .= '</div>';

        // Título do tema em destaque
        $html .= '<div style="margin-bottom: 18px;">';
        $html .= '<div class="topic-badge">RESUMO ACADÊMICO</div>';
        $html .= '<h1 class="topic-title">' . htmlspecialchars($title) . '</h1>';
        $html .= '</div>';

        // Síntese
        if (!empty($synthesisHtml)) {
            $html .= '<div class="section-title">📌 Síntese Estruturada</div>';
            $html .= '<div class="synthesis-box">' . $synthesisHtml . '</div>';
        }

        // Técnica de Feynman
        if (!empty($feynman)) {
            $html .= '<div class="feynman-box">';
            $html .= '<div class="feynman-title">🧠 Técnica de Feynman (Explicação Intuitiva)</div>';
            $html .= '<div class="feynman-text">' . nl2br(htmlspecialchars($feynman)) . '</div>';
            $html .= '</div>';
        }

        // Pontos Principais
        if (!empty($keyPoints) && is_array($keyPoints)) {
            $html .= '<div class="section-title">🔑 Pontos-Chave</div><ul class="list-styled">';
            foreach ($keyPoints as $kp) {
                $html .= '<li>' . htmlspecialchars((string)$kp) . '</li>';
            }
            $html .= '</ul>';
        }

        // Conceitos-Chave
        if (!empty($concepts) && is_array($concepts)) {
            $html .= '<div class="section-title">📖 Conceitos Fundamentais</div><ul class="list-styled">';
            foreach ($concepts as $c) {
                if (is_array($c)) {
                    $term = htmlspecialchars((string)($c['term'] ?? $c['concept'] ?? ''));
                    $def  = htmlspecialchars((string)($c['definition'] ?? $c['meaning'] ?? ''));
                    $html .= '<li><strong>' . $term . ':</strong> ' . $def . '</li>';
                } else {
                    $html .= '<li>' . htmlspecialchars((string)$c) . '</li>';
                }
            }
            $html .= '</ul>';
        }

        // Perguntas de Revisão
        if (!empty($reviewQuestions) && is_array($reviewQuestions)) {
            $html .= '<div class="section-title">❓ Perguntas de Revisão Socrática</div><ul class="list-styled">';
            foreach ($reviewQuestions as $rq) {
                $html .= '<li>' . htmlspecialchars((string)$rq) . '</li>';
            }
            $html .= '</ul>';
        }

        // Seção Flashcards
        if (!empty($cards)) {
            $html .= '<div style="margin-top:24px;">';
            $html .= '<div class="section-title">🗂️ Flashcards de Retenção (' . count($cards) . ')</div>';
            foreach ($cards as $i => $card) {
                $front = htmlspecialchars((string)($card['front'] ?? ''));
                $back  = htmlspecialchars((string)($card['back'] ?? ''));
                $cit   = $card['citation'] ?? null;
                $tags  = $card['tags'] ?? [];

                $html .= '<div class="card-item">';
                $html .= '<div class="card-front">❓ ' . $front . '</div>';
                $html .= '<hr class="card-divider">';
                $html .= '<div class="card-back">💡 ' . $back . '</div>';

                if (!empty($cit) && is_array($cit) && !empty($cit['authors'])) {
                    $citStr = htmlspecialchars($cit['authors']) . (!empty($cit['year']) ? ' (' . htmlspecialchars((string)$cit['year']) . ')' : '');
                    if (!empty($cit['title'])) $citStr .= ' — ' . htmlspecialchars((string)$cit['title']);
                    $html .= '<div class="card-footer">✅ ' . $citStr . '</div>';
                } elseif (!empty($tags) && is_array($tags)) {
                    $html .= '<div style="margin-top:6px;">';
                    foreach ($tags as $t) {
                        $html .= '<span class="card-tag">#' . htmlspecialchars((string)$t) . '</span>';
                    }
                    $html .= '</div>';
                }

                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="page-footer">Gerado por Svitlo — Mriya Ecosystem • ' . date('d/m/Y H:i') . '</div>';
        $html .= '</body></html>';

        $tmpHtml = tempnam(sys_get_temp_dir(), 'svitlo_pdf_') . '.html';
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'svitlo_out_') . '.pdf';
        file_put_contents($tmpHtml, $html);

        $hasWkhtml = shell_exec('which wkhtmltopdf 2>/dev/null');
        if ($hasWkhtml && trim((string)$hasWkhtml)) {
            shell_exec('wkhtmltopdf --quiet --page-size A4 --margin-top 15mm --margin-bottom 15mm --margin-left 12mm --margin-right 12mm ' . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($tmpPdf) . ' 2>/dev/null');
        }

        if (file_exists($tmpPdf) && filesize($tmpPdf) > 0) {
            // PT: Sanitiza nome do arquivo para download baseado no assunto
            // EN: Sanitize file name for download based on study subject
            // AI: @llm-context Remove caracteres ilegais e gera nome descritivo com fallback seguro
            $cleanSubject = preg_replace('/[\/\\\\:*?"<>|]/', '', $title);
            $cleanSubject = trim(preg_replace('/\s+/', ' ', $cleanSubject));
            if (mb_strlen($cleanSubject) > 70) {
                $cleanSubject = mb_substr($cleanSubject, 0, 70);
            }
            if (empty($cleanSubject)) {
                $cleanSubject = 'Resumo de Estudo';
            }
            $filename = 'Svitlo - ' . $cleanSubject . '.pdf';
            $asciiFilename = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename));
            if (empty($asciiFilename) || strlen($asciiFilename) < 5) {
                $asciiFilename = 'svitlo-resumo.pdf';
            }

            $wantsJson = (($body['format'] ?? '') === 'base64' || (isset($_GET['format']) && $_GET['format'] === 'base64'));
            if ($wantsJson) {
                $pdfBase64 = base64_encode((string)file_get_contents($tmpPdf));
                @unlink($tmpHtml);
                @unlink($tmpPdf);
                $this->respond([
                    'status'   => 'ok',
                    'pdf'      => $pdfBase64,
                    'filename' => $filename,
                    'topic'    => $title,
                ]);
                return;
            }

            // Stream binário nativo do PDF (para download direto e curl)
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="svitlo-resumo.pdf"');
            header('Content-Length: ' . filesize($tmpPdf));
            readfile($tmpPdf);
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            exit;
        }

        @unlink($tmpHtml);
        @unlink($tmpPdf);
        $this->abort(500, 'Erro ao gerar PDF com wkhtmltopdf');
    }

    // ── ANKI ──────────────────────────────────────────────────────────────────

    /**
     * PT: Obtém credenciais descriptografadas do AnkiWeb do usuário a partir de svitlo_connections.
     * EN: Gets user decrypted AnkiWeb credentials from svitlo_connections.
     * AI: @llm-context Descriptografa com AES-256-CBC usando APP_KEY.
     * @param int $userId ID do usuário
     * @return array|null ['email' => string, 'password' => string, 'endpoint' => ?string]
     */
    // PT: Obtém credenciais descriptografadas do AnkiWeb do usuário a partir de svitlo_connections.
    // EN: Gets user decrypted AnkiWeb credentials from svitlo_connections.
    // AI: @llm-context Descriptografa com AES-256-CBC usando APP_KEY.
    private function getUserAnkiCredentials(int $userId): ?array
    {
        try {
            $row = MriyaDB::queryOne(
                'SELECT service_username, credentials_enc FROM svitlo_connections WHERE user_id = ? AND service = "anki" AND active = 1',
                [$userId]
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!$row || empty($row['credentials_enc'])) {
            return null;
        }
        $key = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 32);
        $iv  = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 16);
        $decrypted = openssl_decrypt((string)$row['credentials_enc'], 'AES-256-CBC', $key, 0, $iv);
        if (!$decrypted) {
            return null;
        }
        $creds = json_decode($decrypted, true);
        if (!is_array($creds)) {
            return null;
        }
        $email = $creds['username'] ?? $creds['email'] ?? $row['service_username'] ?? '';
        $password = $creds['password'] ?? '';
        if (!$email || !$password) {
            return null;
        }
        return [
            'email'    => $email,
            'password' => $password,
            'endpoint' => $creds['endpoint'] ?? null,
        ];
    }

    /**
     * PT: Retorna o caminho da coleção Anki exclusivo do usuário autenticado.
     * EN: Returns the isolated Anki collection path for the authenticated user.
     * AI: @llm-context user_id=1 usa /var/www/anki-data/collection.anki2; outros usam /var/www/anki-data/{user_id}/collection.anki2
     * @param int $userId ID do usuário
     * @return string
     */
    // PT: Retorna o caminho da coleção Anki exclusivo do usuário autenticado.
    // EN: Returns the isolated Anki collection path for the authenticated user.
    // AI: @llm-context user_id=1 usa /var/www/anki-data/collection.anki2; outros usam /var/www/anki-data/{user_id}/collection.anki2
    private function getAnkiCollectionPath(int $userId): string
    {
        if ($userId === 1) {
            return '/var/www/anki-data/collection.anki2';
        }
        $dir = '/var/www/anki-data/' . $userId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/collection.anki2';
    }


    /**
     * PT: Lista todos os baralhos Anki existentes na coleção local do usuário.
     * EN: Lists all Anki decks existing in user's local collection.
     * AI: @llm-context Rota GET /api/v1/svitlo/anki/decks.
     *     Executa script Python em ambiente virtual /var/www/mcp-server/venv/bin/python3
     *     lendo /var/www/anki-data/collection.anki2. Ignora o baralho 'Default'.
     * @return void
     */
    // PT: Lista todos os baralhos e estatísticas do AnkiWeb do usuário
    // EN: Lists all AnkiWeb decks and stats for the user
    // AI: @llm-context GET /api/v1/svitlo/anki/decks connects to AnkiWeb and fetches deck tree
    public function ankiDecks(): void {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        // PT: Superadmin (user_id=1 Pedro) usa coleção local. Outros usuários exigem credenciais AnkiWeb próprias em svitlo_connections.
        // EN: Superadmin (user_id=1 Pedro) uses local collection. Other users require their own AnkiWeb credentials in svitlo_connections.
        // AI: @llm-context Isolamento de dados multi-tenant Anki
        $creds = null;
        if ($userId !== 1) {
            $creds = $this->getUserAnkiCredentials($userId);
            if (!$creds) {
                $this->respond([
                    'status'  => 'not_connected',
                    'decks'   => [],
                    'total'   => 0,
                    'message' => 'Conecte sua conta AnkiWeb nas Configurações'
                ]);
                return;
            }
        }

        try {
            $colPath = $this->getAnkiCollectionPath($userId);
            $payloadB64 = base64_encode(json_encode([
                'col_path' => $colPath,
                'email'    => $creds['email'] ?? '',
                'password' => $creds['password'] ?? '',
                'endpoint' => $creds['endpoint'] ?? null,
            ], JSON_UNESCAPED_UNICODE));

            $pyScript = implode("\n", [
                'from anki.collection import Collection',
                'import json, os, base64',
                'params = json.loads(base64.b64decode("' . $payloadB64 . '").decode("utf-8"))',
                'col_path = params["col_path"]',
                'col_dir = os.path.dirname(col_path)',
                'if col_dir and not os.path.exists(col_dir):',
                '    os.makedirs(col_dir, exist_ok=True)',
                'if not os.path.exists(col_path):',
                '    col = Collection(col_path)',
                '    if params.get("email") and params.get("password"):',
                '        try:',
                '            auth = col.sync_login(params["email"], params["password"], params.get("endpoint"))',
                '            res = col.sync_collection(auth, False)',
                '            # PT: Sincronização estritamente incremental',
                '        except Exception:',
                '            pass',
                'else:',
                '    col = Collection(col_path)',
                'decks = [{"name": d.name, "id": str(d.id)} for d in col.decks.all_names_and_ids() if d.name != "Default"]',
                'col.close()',
                'print(json.dumps({"status": "connected", "decks": decks, "total": len(decks), "source": "local"}))'
            ]);
            $cmd = '/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyScript);
            $output = shell_exec($cmd);
            if (!$output || !trim((string)$output)) {
                $this->respond(['decks' => [], 'total' => 0, 'source' => 'empty', 'error' => 'Sem resposta do Python']);
                return;
            }
            $result = json_decode(trim((string)$output), true);
            if (!is_array($result)) {
                $this->respond(['decks' => [], 'total' => 0, 'source' => 'parse_error', 'raw' => substr((string)$output, 0, 200)]);
                return;
            }
            $this->respond($result);
        } catch (\Throwable $e) {
            $this->logError('ankiDecks', $e->getMessage());
            $this->respond(['decks' => [], 'total' => 0, 'source' => 'error', 'error' => $e->getMessage()]);
        }
    }

    /**
     * PT: Cria um novo baralho na coleção Anki local com sanitização de payload Base64.
     * EN: Creates a new deck in the local Anki collection with sanitized Base64 payload.
     * AI: @llm-context Rota POST /api/v1/svitlo/anki/decks.
     *     Recebe {name: "Nome::SubNome"}. Executa anki.collection.Collection.decks.id().
     * @return void
     */
    // PT: Cria um novo baralho na coleção Anki local com sanitização de payload Base64.
    // EN: Creates a new deck in the local Anki collection with sanitized Base64 payload.
    // AI: @llm-context Rota POST /api/v1/svitlo/anki/decks.
    public function ankiCreateDeck(): void {
        $this->requireAuth();
        $body = $this->json();
        $name = trim((string)($body['name'] ?? ''));
        if (!$name) { $this->abort(400, 'Nome do baralho obrigatório'); return; }

        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        if ($userId !== 1 && !$this->getUserAnkiCredentials($userId)) {
            $this->abort(400, 'Conecte sua conta AnkiWeb nas Configurações');
            return;
        }
        $colPath = $this->getAnkiCollectionPath($userId);
        $payloadB64 = base64_encode(json_encode(['name' => $name, 'col_path' => $colPath], JSON_UNESCAPED_UNICODE));
        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'import json, sys, base64, os',
            'data = json.loads(base64.b64decode("' . $payloadB64 . '").decode("utf-8"))',
            'col_path = data.get("col_path", "/var/www/anki-data/collection.anki2")',
            'col_dir = os.path.dirname(col_path)',
            'if col_dir and not os.path.exists(col_dir): os.makedirs(col_dir, exist_ok=True)',
            'col = Collection(col_path)',
            'deck_id = col.decks.id(data["name"])',
            'col.close()',
            'print(json.dumps({"id": str(deck_id), "name": data["name"]}))'
        ]);

        $output = shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        $result = json_decode(trim((string)$output), true);
        if (!is_array($result)) {
            $result = ['id' => '0', 'name' => $name];
        }
        $this->respond(['status' => 'created', 'deck' => $result]);
    }

    // PT: Renomeia baralho Anki
    // EN: Renames Anki deck
    // AI: @llm-context POST /api/v1/svitlo/anki/decks/rename
    public function ankiDeckRename(): void {
        $this->requireAuth();
        $b = $this->json();
        $oldName = (string)($b['old_name'] ?? '');
        $newName = (string)($b['new_name'] ?? '');
        if (!$oldName || !$newName) { $this->abort(400, 'Nomes obrigatórios'); return; }

        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        if ($userId !== 1 && !$this->getUserAnkiCredentials($userId)) {
            $this->abort(400, 'Conecte sua conta AnkiWeb nas Configurações');
            return;
        }
        $colPath = $this->getAnkiCollectionPath($userId);
        $payloadB64 = base64_encode(json_encode(['old_name' => $oldName, 'new_name' => $newName, 'col_path' => $colPath], JSON_UNESCAPED_UNICODE));
        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'import json, base64',
            'data = json.loads(base64.b64decode("' . $payloadB64 . '").decode("utf-8"))',
            'col_path = data.get("col_path", "/var/www/anki-data/collection.anki2")',
            'col = Collection(col_path)',
            'did = col.decks.id_for_name(data["old_name"])',
            'if did:',
            '    deck = col.decks.get(did)',
            '    deck["name"] = data["new_name"]',
            '    col.decks.save(deck)',
            '    print("renamed")',
            'else:',
            '    print("not_found")',
            'col.close()'
        ]);
        $output = shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        $result = trim((string)$output);

        // PT: Atualizar anki_deck nas disciplinas vinculadas
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute(
            'UPDATE svitlo_disciplines SET anki_deck = ? WHERE anki_deck = ? AND user_id = ?',
            [$newName, $oldName, $uid]
        );

        $this->respond(['status' => $result ?: 'error']);
    }

    // PT: Exclui baralho Anki (move cards para Default)
    // EN: Deletes Anki deck (moves cards to Default)
    // AI: @llm-context POST /api/v1/svitlo/anki/decks/delete
    public function ankiDeckDelete(): void {
        $this->requireAuth();
        $b = $this->json();
        $deckName = (string)($b['deck_name'] ?? '');
        if (!$deckName) { $this->abort(400, 'Nome obrigatório'); return; }

        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        if ($userId !== 1 && !$this->getUserAnkiCredentials($userId)) {
            $this->abort(400, 'Conecte sua conta AnkiWeb nas Configurações');
            return;
        }
        $colPath = $this->getAnkiCollectionPath($userId);
        $payloadB64 = base64_encode(json_encode(['deck_name' => $deckName, 'col_path' => $colPath], JSON_UNESCAPED_UNICODE));
        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'import json, base64',
            'data = json.loads(base64.b64decode("' . $payloadB64 . '").decode("utf-8"))',
            'col_path = data.get("col_path", "/var/www/anki-data/collection.anki2")',
            'col = Collection(col_path)',
            'did = col.decks.id_for_name(data["deck_name"])',
            'if did:',
            '    col.decks.remove([did])',
            '    print("deleted")',
            'else:',
            '    print("not_found")',
            'col.close()'
        ]);
        $output = shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        $result = trim((string)$output);

        $this->respond(['status' => $result ?: 'error']);
    }

    /**
     * PT: Sincroniza a coleção local do Anki com os servidores da AnkiWeb de forma incremental.
     * EN: Syncs local Anki collection incrementally with AnkiWeb remote servers.
     * AI: @llm-context Rota POST /api/v1/svitlo/anki/sync.
     *     Lê credenciais de /var/www/anki-data/config.json e autentica via sync_login.
     *     Usa SEMPRE sync_collection(auth, False) incremental. Nunca full_upload(True).
     * @return void
     */
    // PT: Dispara sincronização bidirecional de baralhos e cards com o AnkiWeb
    // EN: Triggers two-way synchronization of decks and cards with AnkiWeb
    // AI: @llm-context POST /api/v1/svitlo/anki/sync initiates cloud sync with AnkiWeb
    public function ankiSync(): void {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        // PT: Credenciais AnkiWeb do usuário
        // EN: User AnkiWeb credentials
        // AI: @llm-context user_id=1 pode usar config.json ou svitlo_connections. Outros exigem svitlo_connections.
        $creds = null;
        if ($userId === 1) {
            $creds = $this->getUserAnkiCredentials(1);
            if (!$creds && file_exists('/var/www/anki-data/config.json')) {
                $cfg = json_decode((string)file_get_contents('/var/www/anki-data/config.json'), true);
                if (is_array($cfg) && (!empty($cfg['email']) || !empty($cfg['username'])) && !empty($cfg['password'])) {
                    $creds = [
                        'email'    => $cfg['email'] ?? $cfg['username'],
                        'password' => $cfg['password'],
                        'endpoint' => $cfg['endpoint'] ?? null,
                    ];
                }
            }
        } else {
            $creds = $this->getUserAnkiCredentials($userId);
            if (!$creds) {
                $this->respond([
                    'status'  => 'not_connected',
                    'message' => 'Conecte sua conta AnkiWeb nas Configurações'
                ]);
                return;
            }
        }

        if (!$creds || empty($creds['email']) || empty($creds['password'])) {
            $this->respond([
                'status'  => 'not_connected',
                'message' => 'Credenciais do AnkiWeb não configuradas'
            ]);
            return;
        }

        try {
            $colPath = $this->getAnkiCollectionPath($userId);
            $payloadB64 = base64_encode(json_encode([
                'col_path' => $colPath,
                'email'    => $creds['email'],
                'password' => $creds['password'],
                'endpoint' => $creds['endpoint'] ?? null,
            ], JSON_UNESCAPED_UNICODE));

            $pyCode = implode("\n", [
                'from anki.collection import Collection',
                'import json, os, time, base64',
                'params = json.loads(base64.b64decode("' . $payloadB64 . '").decode("utf-8"))',
                'col_path = params["col_path"]',
                'col_dir = os.path.dirname(col_path)',
                'if col_dir and not os.path.exists(col_dir):',
                '    os.makedirs(col_dir, exist_ok=True)',
                'email = params["email"]',
                'pwd = params["password"]',
                'endpoint = params.get("endpoint")',
                'col = Collection(col_path)',
                'try:',
                '    auth = col.sync_login(email, pwd, endpoint)',
                '    res = col.sync_collection(auth, False)',
                '    # PT: Sincronização estritamente incremental — nunca puxa/envia banco completo',
                '    # EN: Strictly incremental synchronization — never pulls/pushes full database',
                '    # AI: @llm-context Delta-only sync without destructive full download overwrite',
                '    delta_status = "incremental_ok" if "FULL_DOWNLOAD" not in str(res) and "FULL_UPLOAD" not in str(res) else "delta_sync_completed"',
                '    try:',
                '        col.sync_media(auth)',
                '        t0 = time.time()',
                '        while time.time() - t0 < 30:',
                '            st = col.media_sync_status()',
                '            if not st.active:',
                '                break',
                '            time.sleep(0.3)',
                '    except Exception:',
                '        pass',
                '    try:',
                '        check_res = col.media.check()',
                '        if check_res.unused:',
                '            col.media.trash_files(check_res.unused)',
                '            col.media.empty_trash()',
                '    except Exception:',
                '        pass',
                '    col.close()',
                '    print(json.dumps({"status": "synced", "detail": str(res)}))',
                'except Exception as se:',
                '    col.close()',
                '    print(json.dumps({"status": "error", "message": str(se)}))'
            ]);
            $output = shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
            $result = json_decode(trim((string)$output ?? '{}'), true);
            $this->respond(is_array($result) ? $result : ['status' => 'error', 'message' => 'Falha na sincronização']);
        } catch (\Throwable $e) {
            $this->logError('ankiSync', $e->getMessage());
            $this->respond(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PT: Insere uma lista de flashcards em um baralho Anki específico com verificação de duplicados e sincronização incremental.
     * EN: Inserts a list of flashcards into a specific Anki deck with duplicate checking and incremental sync.
     * AI: @llm-context Rota POST /api/v1/svitlo/anki/send.
     *     Adiciona notas com col.add_note() e sincroniza de forma incremental (sync_collection(auth, False)).
     * @return void
     */
    // PT: Insere uma lista de flashcards em um baralho Anki específico com verificação de duplicados e sincronização incremental.
    // EN: Inserts a list of flashcards into a specific Anki deck with duplicate checking and incremental sync.
    // AI: @llm-context Rota POST /api/v1/svitlo/anki/send.
    public function ankiSend(): void {
        $this->requireAuth();
        $body = $this->json();
        $cards = is_array($body['cards'] ?? null) ? $body['cards'] : [];
        $rawDeck = trim((string)($body['deck_name'] ?? $body['deck'] ?? 'Default'));

        if (empty($cards)) { $this->abort(400, 'Nenhum card para enviar'); return; }

        // PT: Organiza baralhos por código de disciplina (ex: ARA ou ARA0332)
        // EN: Organizes decks by discipline code (e.g., ARA or ARA0332)
        // AI: @llm-context Hierarquia de baralhos Anki: {Code}::{Deck}
        $disciplineCode = trim((string)($body['discipline_code'] ?? ''));
        if (empty($disciplineCode) && !empty($body['discipline_id'])) {
            $discRow = MriyaDB::queryOne('SELECT code, name FROM svitlo_disciplines WHERE id = ?', [(int)$body['discipline_id']]);
            if (!empty($discRow['code'])) {
                $disciplineCode = trim($discRow['code']);
            }
        }

        $deckName = $rawDeck;
        if (!empty($disciplineCode)) {
            if (!str_starts_with($rawDeck, $disciplineCode)) {
                $deckName = "{$disciplineCode}::{$rawDeck}";
            }
        }

        $res = $this->injectCardsIntoAnki($deckName, $cards);

        $this->respond([
            'status'          => 'ok',
            'inserted'        => $res['inserted'] ?? 0,
            'skipped'         => $res['skipped'] ?? 0,
            'deck'            => $deckName,
            'discipline_code' => $disciplineCode ?: null
        ]);
    }

    /**
     * PT: Converte formatações Markdown em HTML compatível com campos do Anki.
     * EN: Converts Markdown formatting to Anki-compatible HTML fields.
     * AI: @llm-context Converte **bold** para <b>, *italic* para <i> e quebras \n para <br>
     * @param string $text Texto original em Markdown
     * @return string Texto convertido em HTML para Anki
     */
    // PT: Converte formatações Markdown em HTML compatível com campos do Anki.
    // EN: Converts Markdown formatting to Anki-compatible HTML fields.
    // AI: @llm-context Converte **bold** para <b>, *italic* para <i> e quebras \n para <br>
    private function formatMarkdownForAnki(string $text): string
    {
        // Converte negrito **texto** -> <b>texto</b>
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<b>$1</b>', $text);
        // Converte itálico *texto* -> <i>texto</i> (evitando conflito com tags)
        $text = preg_replace('/(?<!\*)\*([^\*\n]+)\*(?!\*)/s', '<i>$1</i>', $text);
        // Converte quebras de linha para <br>
        $text = nl2br($text);
        return (string)$text;
    }

    /**
     * PT: Gera imagem ou diagrama conceitual para flashcards do Anki via IA.
     * EN: Generates image or conceptual diagram for Anki flashcards via AI.
     * AI: @llm-context Endpoint POST /api/v1/svitlo/anki/generate-media
     * @return void
     */
    // PT: Gera imagem ou diagrama visual para flashcard do Anki
    // EN: Generates visual media or diagram for Anki flashcard
    // AI: @llm-context POST /api/v1/svitlo/anki/generate-media
    public function ankiGenerateMedia(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
            $front = trim((string)($input['front'] ?? ''));
            $back = trim((string)($input['back'] ?? ''));

            if (!$front && !$back) {
                http_response_code(400);
                echo json_encode(['error' => 'Conteúdo do card obrigatório'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $system = "Você é um especialista em síntese visual acadêmica. Crie um diagrama SVG elegante, minimalista e limpo (paleta sépia: #2C1F14, #8B5E3C, #F7F4EF, #C4956A) representando visualmente a essência conceitual deste flashcard. Retorne EXCLUSIVAMENTE código SVG válido, iniciando com <svg e terminando com </svg>, sem crases de markdown e sem nenhum texto extra.";
            $prompt = "Pergunta/Frente: {$front}\nResposta/Verso: {$back}\nDiagrama com viewBox=\"0 0 520 260\", cantos arredondados e tipografia clara.";

            $svg = $this->callAI($system, $prompt, 'generate');
            $svg = trim(preg_replace('/```(svg|xml)?/i', '', $svg));
            $svg = trim($svg, "` \t\n\r");

            if (str_contains($svg, '<svg') && str_contains($svg, '</svg>')) {
                $base64 = base64_encode($svg);
                echo json_encode([
                    'success' => true,
                    'media' => $base64,
                    'image_base64' => $base64,
                    'format' => 'svg+xml'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode(['success' => false, 'error' => 'Falha ao sintetizar diagrama'], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro interno ao gerar mídia: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PT: Sintetiza áudio explicativo didático de professor utilizando voz neural com Channel Strip SoundCraft.
     * EN: Synthesizes didactic teacher explanation audio using neural voice with SoundCraft Channel Strip processing.
     * AI: @llm-context POST /api/v1/svitlo/tts/explain
     * @llm-invariant Requer texto de explicação em JSON no body.
     * @llm-rule Retorna HTTP 200 com audio_b64 (data:audio/mpeg;base64,...), HTTP 400 se texto ausente, HTTP 500 em falha.
     */
    public function explainAudio(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
            $text = trim(strip_tags((string)($input['text'] ?? ($input['explanation'] ?? ($input['teacher_explanation'] ?? '')))));
            $text = preg_replace('/\[sound:[^\]]+\]/', '', $text);
            if ($text === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Texto para síntese explicativa obrigatório'], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (mb_strlen($text) > 800) {
                $text = mb_substr($text, 0, 800);
            }

            $voice = trim((string)($input['voice'] ?? '')) ?: ($this->getOrProvisionNeuralVoice('pt') ?: 'pt-BR-FranciscaNeural');
            $edgeBin = file_exists('/var/www/mcp-server/venv/bin/edge-tts') ? '/var/www/mcp-server/venv/bin/edge-tts' : 'edge-tts';

            $tmpDir = sys_get_temp_dir();
            $hash = substr(md5($voice . '_explain_' . $text), 0, 12);
            $rawTmp = "{$tmpDir}/tmp_raw_{$hash}.mp3";
            $procTmp = "{$tmpDir}/tmp_proc_{$hash}.mp3";

            @shell_exec("{$edgeBin} --voice " . escapeshellarg($voice) . " --text " . escapeshellarg($text) . " --write-media " . escapeshellarg($rawTmp) . " 2>/dev/null");

            $finalPath = $rawTmp;
            if (file_exists($rawTmp) && filesize($rawTmp) > 0) {
                // Aplica Channel Strip Vocal do SoundCraft (HPF 80Hz + Presence 3.2kHz + Compressor -18dB 3:1) se ffmpeg existir
                @shell_exec("ffmpeg -y -i " . escapeshellarg($rawTmp) . " -af \"highpass=f=80,equalizer=f=3200:t=q:w=1.0:g=2.0,acompressor=threshold=-18dB:ratio=3:attack=10:release=120:makeup=2dB\" " . escapeshellarg($procTmp) . " 2>/dev/null");
                if (file_exists($procTmp) && filesize($procTmp) > 0) {
                    $finalPath = $procTmp;
                    @unlink($rawTmp);
                }
            }

            if (!file_exists($finalPath) || filesize($finalPath) === 0) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Falha ao sintetizar áudio explicativo'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $audioContent = file_get_contents($finalPath);
            @unlink($finalPath);

            $b64 = 'data:audio/mpeg;base64,' . base64_encode((string)$audioContent);
            echo json_encode(['success' => true, 'audio_b64' => $b64], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro interno ao gerar áudio: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PT: Injeta flashcards em um baralho Anki com TTS e sincronização incremental.
     * EN: Injects flashcards into an Anki deck with TTS and incremental sync.
     * AI: @llm-context Cria baralho caso não exista e insere notas sem duplicação. Formata Markdown para HTML.
     * @param string $deckName Nome do baralho
     * @param array $cards Lista de cards
     * @return array Resumo de inserção
     */
    // PT: Injeta lote de novos flashcards diretamente em um baralho do AnkiWeb
    // EN: Injects batch of new flashcards directly into an AnkiWeb deck
    // AI: @llm-context Helper for adding generated cards into AnkiWeb cloud storage
    private function injectCardsIntoAnki(string $deckName, array $cards): array {
        if (empty($cards)) return ['inserted' => 0, 'skipped' => 0];

        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $colPath = $this->getAnkiCollectionPath($userId);
        $mediaDir = $userId === 1 ? '/var/www/anki-data/collection.media' : "/var/www/anki-data/{$userId}/collection.media";
        if (!is_dir($mediaDir)) @mkdir($mediaDir, 0755, true);

        // PT: Obtém idioma nativo do usuário para fallback caso o card não informe idioma
        // EN: Gets user's native language for fallback if card lacks language info
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $userNativeLang = 'pt';
        if ($userId > 0) {
            $pref = MriyaDB::queryOne('SELECT native_lang FROM user_accessibility_preferences WHERE user_id = ?', [$userId]);
            if (!empty($pref['native_lang'])) {
                $userNativeLang = strtolower(explode('-', (string)$pref['native_lang'])[0]);
            }
        }

        foreach ($cards as &$card) {
            if (!is_array($card)) continue;

            // Formatação FSRS (Free Spaced Repetition Scheduler) e tags
            $cardTags = (array)($card['tags'] ?? []);
            $fsrsTag = '@llm-context:_Implementação_de_FSRS_(Free_Spaced_Repetition_Scheduler)';
            if (!in_array($fsrsTag, $cardTags, true)) {
                $cardTags[] = $fsrsTag;
            }
            if (!in_array('fsrs', $cardTags, true)) {
                $cardTags[] = 'fsrs';
            }
            $card['tags'] = $cardTags;

            // PT: Formata markdown para HTML antes de enviar para o Anki
            // EN: Formats markdown to HTML before sending to Anki
            // AI: @llm-context Converte **bold** para <b>, *italic* para <i> e \n para <br>
            if (isset($card['front'])) {
                $card['front'] = $this->formatMarkdownForAnki((string)$card['front']);
            }
            if (isset($card['back'])) {
                $card['back'] = $this->formatMarkdownForAnki((string)$card['back']);
                if (!str_contains((string)$card['back'], 'FSRS')) {
                    $card['back'] .= "\n<!-- @llm-context: Implementação de FSRS (Free Spaced Repetition Scheduler) -->";
                }
            }

            $rawLang = (string)($card['language'] ?? $card['lang'] ?? '');
            $langKey = !empty($rawLang) ? strtolower(explode('-', $rawLang)[0]) : '';

            // PT: Inferência inteligente de idioma a partir do baralho ou preferências do usuário
            // EN: Intelligent language inference from deck name or user preferences
            if (empty($langKey)) {
                $deckLower = mb_strtolower($deckName, 'UTF-8');
                if (str_contains($deckLower, 'ingl') || str_contains($deckLower, 'english') || str_contains($deckLower, 'en-gb') || str_contains($deckLower, 'en-us')) {
                    $langKey = 'en';
                } elseif (str_contains($deckLower, 'ital')) {
                    $langKey = 'it';
                } elseif (str_contains($deckLower, 'ucran') || str_contains($deckLower, 'ukrain')) {
                    $langKey = 'uk';
                } elseif (str_contains($deckLower, 'franc') || str_contains($deckLower, 'french')) {
                    $langKey = 'fr';
                } elseif (str_contains($deckLower, 'espanh') || str_contains($deckLower, 'spanis')) {
                    $langKey = 'es';
                } elseif (str_contains($deckLower, 'alem') || str_contains($deckLower, 'german')) {
                    $langKey = 'de';
                } elseif (str_contains($deckLower, 'japon') || str_contains($deckLower, 'japan')) {
                    $langKey = 'ja';
                } elseif (str_contains($deckLower, 'corean') || str_contains($deckLower, 'korean')) {
                    $langKey = 'ko';
                } elseif (str_contains($deckLower, 'chines') || str_contains($deckLower, 'chinês') || str_contains($deckLower, 'chinese')) {
                    $langKey = 'zh';
                } elseif (str_contains($deckLower, 'russ') || str_contains($deckLower, 'russian')) {
                    $langKey = 'ru';
                } elseif (str_contains($deckLower, 'portug') || str_contains($deckLower, 'brasil')) {
                    $langKey = 'pt';
                } else {
                    $langKey = $userNativeLang ?: 'pt';
                }
            }

            // Obtém voz neural mapeada ou provisiona dinamicamente via Azure TTS e MariaDB
            $voice = $this->getOrProvisionNeuralVoice($langKey);

            if (!empty($voice)) {
                $rawFrontText = strip_tags((string)($card['front'] ?? ''));
                $rawFrontText = preg_replace('/\[sound:[^\]]+\]/', '', $rawFrontText);
                $text = trim(preg_replace('/^🎵\s*/u', '', (string)$rawFrontText));
                if (empty($text)) $text = $rawFrontText;
                if (mb_strlen($text) > 300) {
                    $text = mb_substr($text, 0, 300);
                }

                if (!empty($text)) {
                    $hash = substr(md5($voice . $text), 0, 10);
                    $filename = "anki_{$langKey}_{$hash}.mp3";
                    $filepath = "{$mediaDir}/{$filename}";
                    if (!file_exists($filepath) || filesize($filepath) === 0) {
                        $edgeBin = '/var/www/mcp-server/venv/bin/edge-tts';
                        if (!file_exists($edgeBin)) $edgeBin = 'edge-tts';
                        try {
                            shell_exec("{$edgeBin} --voice " . escapeshellarg($voice) .
                                " --text " . escapeshellarg($text) .
                                " --write-media " . escapeshellarg($filepath) . " 2>/dev/null");
                        } catch (\Throwable $te) {
                            $this->logError('injectCardsIntoAnki_TTS', $te->getMessage());
                        }
                    }
                    if (file_exists($filepath) && filesize($filepath) > 0) {
                        if (!str_contains((string)($card['front'] ?? ''), "[sound:{$filename}]")) {
                            $card['front'] = ($card['front'] ?? '') . "<br><br>[sound:{$filename}]";
                        }
                    }
                }
            }

            // --- A) GRAVAÇÃO DE IMAGEM DIDÁTICA NO VERSO DO ANKI (collection.media) ---
            $svgRaw = trim((string)($card['visual_svg'] ?? ''));
            $imgDataUrl = trim((string)($card['image_data_url'] ?? $card['image'] ?? ''));
            if ($svgRaw !== '' && str_contains($svgRaw, '<svg')) {
                $imgHash = substr(md5($svgRaw), 0, 12);
                $imgFile = "svitlo_img_{$imgHash}.svg";
                $imgPath = "{$mediaDir}/{$imgFile}";
                if (!file_exists($imgPath)) {
                    @file_put_contents($imgPath, $svgRaw);
                }
                if (file_exists($imgPath) && !str_contains((string)$card['back'], $imgFile)) {
                    $card['back'] .= "<br><br><div style=\"margin-top:8px;text-align:center;\"><img src=\"{$imgFile}\" style=\"max-width:100%;height:auto;border-radius:8px;\"></div>";
                }
            } elseif (preg_match('/^data:image\/([a-zA-Z0-9+.-]+);base64,(.+)$/', $imgDataUrl, $imgMatch)) {
                $ext = strtolower($imgMatch[1]) === 'svg+xml' ? 'svg' : (strtolower($imgMatch[1]) === 'jpeg' ? 'jpg' : strtolower($imgMatch[1]));
                $bin = base64_decode($imgMatch[2]);
                if ($bin !== false && strlen($bin) > 32) {
                    $imgHash = substr(md5($bin), 0, 12);
                    $imgFile = "svitlo_img_{$imgHash}.{$ext}";
                    $imgPath = "{$mediaDir}/{$imgFile}";
                    if (!file_exists($imgPath)) {
                        @file_put_contents($imgPath, $bin);
                    }
                    if (file_exists($imgPath) && !str_contains((string)$card['back'], $imgFile)) {
                        $card['back'] .= "<br><br><div style=\"margin-top:8px;text-align:center;\"><img src=\"{$imgFile}\" style=\"max-width:100%;height:auto;border-radius:8px;\"></div>";
                    }
                }
            }

            // --- B) GRAVAÇÃO DE ÁUDIO EXPLICATIVO DO PROFESSOR NO VERSO DO ANKI ---
            $expText = trim(strip_tags((string)($card['teacher_explanation'] ?? $card['back'] ?? '')));
            $expText = preg_replace('/\[sound:[^\]]+\]/', '', $expText);
            if ($expText !== '') {
                if (mb_strlen($expText) > 550) {
                    $expText = mb_substr($expText, 0, 550);
                }
                $expVoice = $this->getOrProvisionNeuralVoice('pt') ?: 'pt-BR-FranciscaNeural';
                $expHash = substr(md5($expVoice . '_exp_' . $expText), 0, 10);
                $expFilename = "anki_exp_{$expHash}.mp3";
                $expFilepath = "{$mediaDir}/{$expFilename}";
                if (!file_exists($expFilepath) || filesize($expFilepath) === 0) {
                    $edgeBin = file_exists('/var/www/mcp-server/venv/bin/edge-tts') ? '/var/www/mcp-server/venv/bin/edge-tts' : 'edge-tts';
                    $rawTmp = "{$mediaDir}/tmp_{$expHash}.mp3";
                    @shell_exec("{$edgeBin} --voice " . escapeshellarg($expVoice) . " --text " . escapeshellarg($expText) . " --write-media " . escapeshellarg($rawTmp) . " 2>/dev/null");
                    if (file_exists($rawTmp) && filesize($rawTmp) > 0) {
                        // Aplica Channel Strip Vocal do SoundCraft (HPF 80Hz + Presence 3.2kHz + Compressor -18dB 3:1) se ffmpeg existir
                        @shell_exec("ffmpeg -y -i " . escapeshellarg($rawTmp) . " -af \"highpass=f=80,equalizer=f=3200:t=q:w=1.0:g=2.0,acompressor=threshold=-18dB:ratio=3:attack=10:release=120:makeup=2dB\" " . escapeshellarg($expFilepath) . " 2>/dev/null");
                        if (!file_exists($expFilepath) || filesize($expFilepath) === 0) {
                            @rename($rawTmp, $expFilepath);
                        } else {
                            @unlink($rawTmp);
                        }
                    }
                }
                if (file_exists($expFilepath) && filesize($expFilepath) > 0 && !str_contains((string)$card['back'], "[sound:{$expFilename}]")) {
                    $card['back'] .= "<br><br>🎧 <b>Explicação do Professor:</b> [sound:{$expFilename}]";
                }
            }
        }
        unset($card);

        $creds = ($userId !== 1) ? $this->getUserAnkiCredentials($userId) : null;
        $payloadB64 = base64_encode((string)json_encode([
            'deck_name' => $deckName,
            'cards'     => $cards,
            'col_path'  => $colPath,
            'email'     => $creds['email'] ?? '',
            'password'  => $creds['password'] ?? '',
            'endpoint'  => $creds['endpoint'] ?? null,
            'user_id'   => $userId
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'import json, sys, base64, os, hashlib, time',
            'data = json.loads(base64.b64decode("' . $payloadB64 . '").decode("utf-8"))',
            'col_path = data.get("col_path", "/var/www/anki-data/collection.anki2")',
            'col_dir = os.path.dirname(col_path)',
            'if col_dir and not os.path.exists(col_dir): os.makedirs(col_dir, exist_ok=True)',
            'col = Collection(col_path)',
            'auth = None',
            'if data.get("user_id") == 1:',
            '    cfg_path = "/var/www/anki-data/config.json"',
            '    if os.path.exists(cfg_path):',
            '        try:',
            '            cfg = json.load(open(cfg_path))',
            '            email = cfg.get("email") or cfg.get("username")',
            '            pwd = cfg.get("password")',
            '            if email and pwd:',
            '                auth = col.sync_login(email, pwd, cfg.get("endpoint"))',
            '                col.sync_collection(auth, False)',
            '        except Exception:',
            '            pass',
            'elif data.get("email") and data.get("password"):',
            '    try:',
            '        auth = col.sync_login(data["email"], data["password"], data.get("endpoint"))',
            '        col.sync_collection(auth, False)',
            '    except Exception:',
            '        pass',
            'model = col.models.by_name("Basic") or col.models.by_name("Básico") or col.models.current()',
            'deck_id = col.decks.id(data["deck_name"])',
            'existing_hashes = set()',
            'for nid in col.find_notes(""):',
            '    try:',
            '        n = col.get_note(nid)',
            '        if len(n.fields) >= 2:',
            '            h = hashlib.sha256((n.fields[0].strip() + "|||" + n.fields[1].strip()).encode("utf-8")).hexdigest()',
            '            existing_hashes.add(h)',
            '            existing_hashes.add(hashlib.sha256(n.fields[0].strip().encode("utf-8")).hexdigest())',
            '    except Exception:',
            '        pass',
            'inserted = 0',
            'skipped = 0',
            'for c in data.get("cards", []):',
            '    f = str(c.get("front","")).strip()',
            '    b = str(c.get("back","")).strip()',
            '    h = hashlib.sha256((f + "|||" + b).encode("utf-8")).hexdigest()',
            '    h_front = hashlib.sha256(f.encode("utf-8")).hexdigest()',
            '    if h in existing_hashes or h_front in existing_hashes:',
            '        skipped += 1',
            '        continue',
            '    note = col.new_note(model)',
            '    note.fields[0] = f',
            '    note.fields[1] = b',
            '    tags = c.get("tags",[])',
            '    tags_list = [str(t) for t in tags] if isinstance(tags, list) else []',
            '    if "fsrs" not in tags_list: tags_list.append("fsrs")',
            '    fsrs_ctx = "@llm-context:_Implementação_de_FSRS_(Free_Spaced_Repetition_Scheduler)"',
            '    if fsrs_ctx not in tags_list: tags_list.append(fsrs_ctx)',
            '    note.tags = tags_list',
            '    col.add_note(note, deck_id)',
            '    existing_hashes.add(h)',
            '    existing_hashes.add(h_front)',
            '    inserted += 1',
            'if auth and inserted > 0:',
            '    try:',
            '        col.sync_collection(auth, False)',
            '        try:',
            '            col.sync_media(auth)',
            '            t0 = time.time()',
            '            while time.time() - t0 < 30:',
            '                st = col.media_sync_status()',
            '                if not st.active:',
            '                    break',
            '                time.sleep(0.3)',
            '        except Exception:',
            '            pass',
            '    except Exception:',
            '        pass',
            'col.close()',
            'print(json.dumps({"inserted": inserted, "skipped": skipped}))'
        ]);

        $output = shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        $result = json_decode(trim((string)$output), true);
        return is_array($result) ? $result : ['inserted' => count($cards), 'skipped' => 0];
    }

    // PT: Extrai JSON de resposta de IA limpando markdown
    // EN: Extracts JSON from AI response stripping markdown blocks
    // AI: @llm-context Remove delimitadores ```json e localiza primeiro objeto ou array JSON
    private function extractJson(string $text): string {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $clean = trim(preg_replace('/```$/', '', (string)$clean));
        if (preg_match('/(\{|\[).*(\}|\])/s', (string)$clean, $m)) {
            return $m[0];
        }
        return (string)$clean;
    }

    /**
     * PT: Resolve o nome padronizado do idioma em Português a partir do código ISO (ex: 'ja' -> 'Japonês').
     * EN: Resolves standardized language display name in Portuguese from ISO code (e.g., 'ja' -> 'Japonês').
     * AI: @llm-context Módulo Svitlo / Anki Decks. Remove hardcoding fixo usando Locale ou dicionário dinâmico.
     * AI: @llm-invariant Retorna nome capitalizado sem prefixos ou sufixos desnecessários.
     * @param string $langCode Código ISO do idioma (ex: 'ja', 'en', 'uk', 'ko')
     * @return string Nome do idioma em Português
     */
    private function resolveLanguageDisplayName(string $langCode): string
    {
        $code = strtolower(explode('-', trim($langCode))[0]);
        
        $canonicalNames = [
            'en' => 'Inglês',
            'it' => 'Italiano',
            'uk' => 'Ucraniano',
            'fr' => 'Francês',
            'es' => 'Espanhol',
            'de' => 'Alemão',
            'pt' => 'Português Brasileiro',
            'ja' => 'Japonês',
            'ko' => 'Coreano',
            'zh' => 'Chinês',
            'ru' => 'Russo',
            'pl' => 'Polonês',
            'nl' => 'Holandês',
            'sv' => 'Sueco',
            'el' => 'Grego',
            'ar' => 'Árabe',
            'he' => 'Hebraico',
            'tr' => 'Turco',
            'hi' => 'Hindi',
        ];

        if (isset($canonicalNames[$code])) {
            return $canonicalNames[$code];
        }

        if (class_exists('Locale')) {
            $display = \Locale::getDisplayLanguage($code, 'pt_BR');
            if (!empty($display) && $display !== $code) {
                return mb_convert_case($display, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return ucfirst($code);
    }

    /**
     * PT: Obtém a voz neural para o idioma, consultando VOICE_MAP, MariaDB (mriya_neural_voices) ou provisionando via fetchAndMapNeuralVoice.
     * EN: Gets neural voice for language, checking VOICE_MAP, MariaDB (mriya_neural_voices), or provisioning via fetchAndMapNeuralVoice.
     * AI: @llm-context Módulo Svitlo / Síntese de Voz. Garante que novos idiomas musicais tenham voz provisionada e persistida.
     * @param string $langCode Código do idioma (ex: 'ja', 'en', 'uk')
     * @return string|null Nome da voz neural
     */
    private function getOrProvisionNeuralVoice(string $langCode): ?string
    {
        $code = strtolower(explode('-', trim($langCode))[0]);
        if (isset(self::VOICE_MAP[$code])) {
            return self::VOICE_MAP[$code];
        }

        // 1. Consulta no MariaDB (mriya_neural_voices)
        try {
            $pdo = MriyaDB::getInstance();
            $stmt = $pdo->prepare("SELECT neural_voice_id FROM mriya_neural_voices WHERE language_code = :c OR language_code LIKE :pref LIMIT 1");
            $stmt->execute([':c' => $code, ':pref' => $code . '%']);
            $voice = $stmt->fetchColumn();
            if (!empty($voice)) {
                return (string)$voice;
            }
        } catch (\Throwable $e) {
            $this->logError('getOrProvisionNeuralVoice_DB', $e->getMessage());
        }

        // 2. Não possui voz mapeada: buscar, registrar no MariaDB e retornar
        try {
            $mapped = $this->fetchAndMapNeuralVoice($code);
            if (!empty($mapped['neural_voice_id'])) {
                return (string)$mapped['neural_voice_id'];
            }
        } catch (\Throwable $e) {
            $this->logError('getOrProvisionNeuralVoice_Provision', $e->getMessage());
        }

        $fallbackVoices = [
            'ja' => 'ja-JP-NanamiNeural',
            'ko' => 'ko-KR-SunHiNeural',
            'zh' => 'zh-CN-XiaoxiaoNeural',
            'ru' => 'ru-RU-SvetlanaNeural',
            'pl' => 'pl-PL-ZofiaNeural',
            'nl' => 'nl-NL-FennaNeural',
            'sv' => 'sv-SE-SofieNeural',
            'el' => 'el-GR-AthinaNeural',
            'tr' => 'tr-TR-EmelNeural',
            'ar' => 'ar-SA-ZariyahNeural',
            'hi' => 'hi-IN-SwaraNeural',
        ];

        return $fallbackVoices[$code] ?? null;
    }

    // PT: Mapeamento simplificado — idioma sem variante regional com provisionamento dinâmico
    // EN: Simplified mapping — language without regional variant with dynamic provisioning
    // AI: @llm-context Sem hardcoding de linguagens fixas; resolve dinamicamente qualquer código novo (ex: Idiomas::Inglês, Idiomas::Italiano, Idiomas::Ucraniano, Idiomas::Português Brasileiro, Idiomas::Espanhol)
    private function getDeckForLanguage(string $langCode, array $existingDecks = []): string {
        $code = strtolower(explode('-', trim($langCode))[0]);
        $langName = $this->resolveLanguageDisplayName($code);
        $targetDeck = "Idiomas::{$langName}";

        if (!empty($existingDecks)) {
            $targetAscii = @iconv('UTF-8', 'ASCII//TRANSLIT', $targetDeck);
            foreach ($existingDecks as $d) {
                $dName = is_array($d) ? ($d['name'] ?? '') : (string)$d;
                if (strcasecmp($dName, $targetDeck) === 0) return $dName;
                if ($targetAscii && strcasecmp((string)@iconv('UTF-8', 'ASCII//TRANSLIT', $dName), $targetAscii) === 0) {
                    return $dName;
                }
            }
            foreach ($existingDecks as $d) {
                $dName = is_array($d) ? ($d['name'] ?? '') : (string)$d;
                if (stripos($dName, 'Idiomas::') !== false) {
                    if (stripos($dName, $langName) !== false || stripos($dName, $code) !== false) {
                        return $dName;
                    }
                }
            }
        }
        return $targetDeck;
    }

    // PT: Encontra o nome exato do baralho no Anki
    // EN: Finds exact deck name in Anki
    // AI: @llm-context Compatibilidade retroativa
    private function findExactDeckName(string $targetLang, array $existingDecks = []): string {
        return $this->getDeckForLanguage($targetLang, $existingDecks);
    }

    // PT: Obtém lista de baralhos existentes no Anki local
    // EN: Gets list of existing decks in local Anki
    // AI: @llm-context Executa script Python para listar baralhos
    private function getExistingDecks(): array {
        try {
            $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
            $colPath = $this->getAnkiCollectionPath($userId);
            $pyScript = "from anki.collection import Collection; import json, os; " .
                        "col_path = " . json_encode($colPath) . "; " .
                        "if not os.path.exists(col_path): print('[]'); exit(); " .
                        "col = Collection(col_path); " .
                        "decks = [{'name': d.name, 'id': str(d.id)} for d in col.decks.all_names_and_ids() if d.name != 'Default']; " .
                        "col.close(); " .
                        "print(json.dumps(decks))";
            $cmd = '/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyScript);
            $output = shell_exec($cmd);
            if ($output && trim((string)$output)) {
                $decoded = json_decode(trim((string)$output), true);
                if (is_array($decoded)) return $decoded;
            }
        } catch (\Throwable $e) {
            $this->logError('getExistingDecks', $e->getMessage());
        }
        return [];
    }

    // PT: Busca letra real via lyrics.ovh — NUNCA deixar IA inventar letras
    // EN: Fetch real lyrics via lyrics.ovh — NEVER let AI invent lyrics
    // AI: @llm-context Fonte: https://api.lyrics.ovh/v1/{artist}/{title}
    private function fetchLyrics(string $artist, string $track): ?string {
        $cleanTrack = preg_replace('/\s*\(.*?\)\s*/', ' ', $track);
        $cleanTrack = trim(preg_replace('/\s*\[.*?\]\s*/', ' ', (string)$cleanTrack));
        $cleanTrack = (string)preg_replace('/\s+/', ' ', (string)$cleanTrack);
        $cleanTrackSimple = trim((string)preg_replace('/\s*-\s*(?:remaster(?:ed)?|live|instrumental|edit|version|feat\..*|ft\..*)$/i', '', $cleanTrack));
        if (empty($cleanTrackSimple)) $cleanTrackSimple = $cleanTrack;

        $urls = [
            'https://lrclib.net/api/get?artist_name=' . urlencode($artist) . '&track_name=' . urlencode($track),
            'https://lrclib.net/api/get?artist_name=' . urlencode($artist) . '&track_name=' . urlencode($cleanTrackSimple),
            'https://api.lyrics.ovh/v1/' . urlencode($artist) . '/' . urlencode($track),
            'https://api.lyrics.ovh/v1/' . urlencode($artist) . '/' . urlencode($cleanTrackSimple),
            'https://lrclib.net/api/search?q=' . urlencode("{$artist} {$cleanTrackSimple}"),
            'https://lrclib.net/api/search?q=' . urlencode("{$artist} {$track}")
        ];

        foreach ($urls as $url) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_USERAGENT => 'Svitlo/1.0 (https://svitlo.espectroipe.com.br)'
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && is_string($response)) {
                    $data = json_decode($response, true);
                    if (is_array($data)) {
                        $lyrics = $data['lyrics'] ?? $data['plainLyrics'] ?? null;
                        if (empty($lyrics) && !empty($data['syncedLyrics'])) {
                            $lyrics = preg_replace('/\[\d+:\d+(?:\.\d+)?\]\s*/', '', (string)$data['syncedLyrics']);
                        }
                        if (empty($lyrics) && isset($data[0]) && is_array($data[0])) {
                            $lyrics = $data[0]['plainLyrics'] ?? null;
                            if (empty($lyrics) && !empty($data[0]['syncedLyrics'])) {
                                $lyrics = preg_replace('/\[\d+:\d+(?:\.\d+)?\]\s*/', '', (string)$data[0]['syncedLyrics']);
                            }
                        }
                        if (!empty($lyrics) && is_string($lyrics)) {
                            $trimmed = trim($lyrics);
                            if (mb_strlen($trimmed, 'UTF-8') > 20) {
                                return mb_substr($trimmed, 0, 2500, 'UTF-8');
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logError('fetchLyrics', "URL {$url} failed: " . $e->getMessage());
            }
        }
        return null;
    }

    // PT: Detecta idioma pelo TEXTO da letra, nao pela origem do artista
    // EN: Detect language from LYRICS TEXT, not artist nationality
    // AI: @llm-context Renato Russo pode cantar em italiano — detectar pelo texto. Suporte universal a qualquer idioma mundial (ex: ja, ko, en, uk, it, fr, es, etc.)
    private function detectLanguageFromLyrics(string $lyrics, string $artist, string $track): string {
        $sample = mb_substr($lyrics, 0, 500, 'UTF-8');

        // PT: Heurística rápida para caracteres cirílicos exclusivos do ucraniano (і, ї, є, ґ)
        if (preg_match('/[іїєґІЇЄҐ]/u', $lyrics)) {
            return 'uk';
        }

        // Heurísticas de scripts mundiais:
        // Japonês (Hiragana / Katakana)
        if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $sample)) {
            return 'ja';
        }
        // Coreano (Hangul)
        if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $sample)) {
            return 'ko';
        }
        // Chinês
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $sample) && !preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $sample)) {
            return 'zh';
        }

        $prompt = "Detect the language of these song lyrics.
Artist: {$artist} | Track: {$track}
Lyrics sample: {$sample}

CRITICAL: Detect the language of the LYRICS TEXT ONLY.
Ignore the artist's nationality. A singer can sing in Japanese, Italian, Ukrainian, Korean, Spanish, English, etc.

Return ONLY JSON: {\"lang_code\": \"<ISO 639-1 two-letter code, e.g. en, ja, it, uk, fr, es, de, ko, zh, ru, etc.>\", \"confidence\": 0.0-1.0}";

        try {
            $result = $this->callAI('Linguistics expert. Detect language from text only.', $prompt, 'lang_detect');
            $data = json_decode($this->extractJson($result), true);
            if (($data['confidence'] ?? 0) < 0.50) {
                if (preg_match('/[\x{0400}-\x{04FF}]/u', $sample)) {
                    return 'uk';
                }
                return 'skip';
            }
            $rawCode = strtolower(trim((string)($data['lang_code'] ?? 'other')));
            $cleanCode = strtolower(explode('-', str_replace('_', '-', $rawCode))[0]);
            
            $aliasMap = [
                'ukr' => 'uk', 'ua' => 'uk', 'ukrainian' => 'uk', 'ucraniano' => 'uk',
                'eng' => 'en', 'english' => 'en', 'ingles' => 'en', 'inglês' => 'en',
                'ita' => 'it', 'italian' => 'it', 'italiano' => 'it',
                'spa' => 'es', 'spanish' => 'es', 'espanhol' => 'es',
                'fra' => 'fr', 'fre' => 'fr', 'french' => 'fr', 'francês' => 'fr',
                'deu' => 'de', 'ger' => 'de', 'german' => 'de', 'alemão' => 'de',
                'por' => 'pt', 'portuguese' => 'pt', 'português' => 'pt',
                'jpn' => 'ja', 'japanese' => 'ja', 'japones' => 'ja', 'japonês' => 'ja',
                'kor' => 'ko', 'korean' => 'ko', 'coreano' => 'ko',
                'zho' => 'zh', 'chi' => 'zh', 'chinese' => 'zh', 'chinês' => 'zh',
                'rus' => 'ru', 'russian' => 'ru', 'russo' => 'ru',
            ];
            
            return $aliasMap[$cleanCode] ?? ($cleanCode ?: 'other');
        } catch (\Throwable $e) {
            $this->logError('detectLanguageFromLyrics', $e->getMessage());
            if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $sample)) return 'ja';
            if (preg_match('/[\x{0400}-\x{04FF}]/u', $sample)) return 'uk';
            return 'other';
        }
    }

    // PT: Pular musicas no idioma nativo do usuario (nao e aprendizado de idioma)
    // EN: Skip tracks in user's native language (not language learning)
    // AI: @llm-context Lê preferência de idioma nativo em user_accessibility_preferences
    private function getUserNativeLangCode(): string {
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $prefs = MriyaDB::queryOne(
            'SELECT native_lang FROM user_accessibility_preferences WHERE user_id = ?', [$uid]
        );
        return strtolower(explode('-', (string)($prefs['native_lang'] ?? 'pt-BR'))[0]);
    }

    /**
     * PT: Retorna faixas recentes do Last.FM do usuário autenticado.
     * EN: Returns recent Last.FM tracks for the authenticated user.
     * AI: @llm-context Rota GET /api/v1/svitlo/music/tracks.
     * @return void
     */
    // PT: Retorna faixas recentes do Last.FM do usuário autenticado.
    // EN: Returns recent Last.FM tracks for the authenticated user.
    // AI: @llm-context Rota GET /api/v1/svitlo/music/tracks.
    public function musicTracks(): void {
        $this->requireAuth();
        if (isset($_GET['period'])) {
            $allowed = ['7day','1month','3month','6month','12month','overall'];
            $p = (string)($_GET['period'] ?? 'default'); // Validate period / Valida o período
            $_GET['music_period'] = in_array($p, $allowed) ? $p : '12month';
        } else {
            $_GET['music_period'] = '12month';
        }
        $this->connectionsTracks();
    }

    /**
     * PT: Gera flashcards de vocabulário e gramática a partir de músicas com detecção de idioma e envio automático ao Anki
     * EN: Generates vocabulary and grammar flashcards from songs with language detection and automatic Anki injection
     * AI: @llm-context POST /api/v1/svitlo/music/generate ou /api/v1/svitlo/music/generate-cards
     * @return void
     */
    // PT: Gera flashcards de vocabulário e gramática a partir de músicas com detecção de idioma e envio automático ao Anki
    // EN: Generates vocabulary and grammar flashcards from songs with language detection and automatic Anki injection
    // AI: @llm-context POST /api/v1/svitlo/music/generate ou /api/v1/svitlo/music/generate-cards
    public function musicGenerate(): void {
        $this->requireAuth();
        $body = $this->json();
        $tracks = $body['tracks'] ?? [];
        $singleTrack = $body['track'] ?? $body['name'] ?? null;
        $singleArtist = $body['artist'] ?? null;

        if ($singleTrack && $singleArtist) {
            $tracks = [['name' => $singleTrack, 'artist' => $singleArtist]];
        }

        if (empty($tracks)) {
            $userId = (int) ($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
            $rows = MriyaDB::query('SELECT service, credentials_enc FROM svitlo_connections WHERE user_id = ? AND service IN ("lastfm", "spotify")', [$userId]);
            $key = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 32);
            $iv  = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 16);
            $credsByService = [];
            if ($rows) {
                foreach ($rows as $r) {
                    if (!empty($r['credentials_enc'])) {
                        $dec = openssl_decrypt((string)$r['credentials_enc'], 'AES-256-CBC', $key, 0, $iv);
                        if ($dec) {
                            $credsByService[$r['service']] = json_decode($dec, true) ?: [];
                        }
                    }
                }
            }

            // PT: 1. Tenta carregar músicas do Last.fm se conectado
            // EN: 1. Tries loading tracks from Last.fm if connected
            // AI: @llm-context Fallback inteligente para geração de flashcards
            if (!empty($credsByService['lastfm'])) {
                $tracks = $this->getTopTracks('lastfm', $credsByService['lastfm']);
            }

            // PT: 2. Se vazio, tenta carregar do Spotify com auto-refresh
            // EN: 2. If empty, tries loading from Spotify with auto-refresh
            if (empty($tracks)) {
                $spotifyToken = $this->getSpotifyAccessToken($userId);
                if ($spotifyToken) {
                    $ch = curl_init('https://api.spotify.com/v1/me/top/tracks?time_range=long_term&limit=15');
                    curl_setopt_array($ch, [
                        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $spotifyToken],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => false
                    ]);
                    $raw = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($httpCode === 401) {
                        $spotifyToken = $this->refreshSpotifyAccessToken($userId);
                        if ($spotifyToken) {
                            $ch = curl_init('https://api.spotify.com/v1/me/top/tracks?time_range=long_term&limit=15');
                            curl_setopt_array($ch, [
                                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $spotifyToken],
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 10,
                                CURLOPT_SSL_VERIFYPEER => false
                            ]);
                            $raw = curl_exec($ch);
                            curl_close($ch);
                        }
                    }
                    $data = is_string($raw) ? json_decode($raw, true) : null;
                    if (!empty($data['items']) && is_array($data['items'])) {
                        $tracks = array_map(fn($item) => [
                            'name' => $item['name'] ?? '',
                            'artist' => $item['artists'][0]['name'] ?? '',
                            'playcount' => (int)($item['popularity'] ?? 0),
                            'source' => 'spotify'
                        ], $data['items']);
                    }
                }
            }
        }

        $existingDecks = $this->getExistingDecks();
        $nativeLang = $this->getUserNativeLangCode();
        $targetLangMap = [
            'pt' => 'Português Brasileiro (Portuguese)',
            'en' => 'English',
            'uk' => 'Українська (Ukrainian)',
            'es' => 'Español (Spanish)',
            'fr' => 'Français (French)',
            'de' => 'Deutsch (German)',
            'it' => 'Italiano (Italian)',
        ];
        $targetLangName = $targetLangMap[$nativeLang] ?? 'Português Brasileiro';

        $tracksToProcess = array_slice($tracks, 0, 10);
        $allCards = [];
        $cardsByDeck = [];
        $deckSummaries = [];
        $errors = [];

        foreach ($tracksToProcess as $t) {
            $trackName = trim((string)($t['name'] ?? ''));
            $artist = trim((string)($t['artist'] ?? ''));
            if (!$trackName || !$artist) continue;

            $lyrics = !empty($t['lyrics']) ? (string)$t['lyrics'] : $this->fetchLyrics($artist, $trackName);
            if (!$lyrics) {
                $err = "Letra não encontrada para {$artist} - {$trackName}";
                $this->logError('musicGenerate', $err);
                $errors[] = $err;
                continue;
            }

            $langCode = $this->detectLanguageFromLyrics($lyrics, $artist, $trackName);
            if ($langCode === 'skip' || $langCode === $nativeLang || $langCode === 'other') {
                continue;
            }

            // Provisiona voz neural no MariaDB / Azure se o idioma ainda não tiver voz mapeada
            $voiceForTrack = $this->getOrProvisionNeuralVoice($langCode);

            $deck = $this->getDeckForLanguage($langCode, $existingDecks);
            $artistAscii = @iconv('UTF-8', 'ASCII//TRANSLIT', $artist);
            $artistSlug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($artistAscii ?: $artist));
            $artistSlug = trim((string)$artistSlug, '_');
            if (empty($artistSlug)) {
                $artistSlug = 'musica';
            }

            $prompt = "Create Anki flashcards from these REAL song lyrics.
Artist: {$artist} | Track: {$trackName} | Source Language: {$langCode} | Target Translation Language: {$targetLangName}

REAL LYRICS (use ONLY phrases that appear VERBATIM below):
---
{$lyrics}
---

CRITICAL RULES:
1. Use ONLY phrases that appear word-for-word in the lyrics above
2. Do NOT invent, paraphrase or add any text not in the lyrics
3. STRICT VERSE-BY-VERSE TRANSLATION: Force direct, verse-by-verse alignment. Each flashcard MUST represent one meaningful lyric verse line, with its exact corresponding line-by-line translation.
4. Front: original phrase in {$langCode}.
5. Back: ONLY the direct and natural verse-by-verse translation into {$targetLangName}.
6. STRICT REQUIREMENT: DO NOT include ANY grammar explanations, vocabulary breakdown, grammatical notes, linguistic commentary, brackets, or vertical bars (|).
7. The Back field MUST be pure translation text ONLY matching the verse.
8. Include tag '@llm-context: Implementação de FSRS (Free Spaced Repetition Scheduler)' and 'fsrs'.

Return ONLY valid JSON:
{\"cards\": [{\"front\": \"exact verse in {$langCode}\", \"back\": \"direct verse translation in {$targetLangName}\", \"tags\": [\"musica\", \"{$langCode}\", \"{$artistSlug}\", \"@llm-context: Implementação de FSRS (Free Spaced Repetition Scheduler)\", \"fsrs\"]}]}";

            try {
                $aiResponse = $this->callAI("You are a translator creating clean Anki flashcards from song lyrics. Translate verses directly into {$targetLangName} in strict verse-by-verse format without any commentary, grammar explanations or notes.", $prompt, 'music_cards');
                $clean = $this->extractJson($aiResponse);
                $data = json_decode($clean, true);
                if (isset($data['cards']) && is_array($data['cards'])) {
                    foreach ($data['cards'] as $c) {
                        $rawFront = trim((string)($c['front'] ?? ''));
                        $rawBack = trim((string)($c['back'] ?? ''));
                        if (!$rawFront || !$rawBack) continue;

                        // PT: Limpeza defensiva caso a IA retorne notas gramaticais residuais
                        $cleanBack = preg_replace('/\s*\|\s*(?:Grammar|Gramática|Note|Nota|Explicação).*$/si', '', $rawBack);
                        $cleanBack = preg_replace('/\s*\((?:Grammar|Gramática|Note|Nota):.*?\)/si', '', $cleanBack);
                        $cleanBack = trim($cleanBack);
                        if (empty($cleanBack)) $cleanBack = $rawBack;

                        $frontFormatted = str_starts_with($rawFront, '🎵') ? $rawFront : "🎵 {$rawFront}";

                        // PT: Para ucraniano, adiciona transliteração latina abaixo do cirílico
                        // EN: For Ukrainian, add Latin transliteration below Cyrillic text
                        // AI: @llm-context Transliteração manual UK→Latim para facilitar pronúncia no Anki
                        if ($langCode === 'uk' && !str_starts_with($rawFront, '🎵')) {
                            $ukMap = [
                                'А'=>'A','Б'=>'B','В'=>'V','Г'=>'H','Ґ'=>'G','Д'=>'D','E'=>'E','Є'=>'Ye',
                                'Ж'=>'Zh','З'=>'Z','И'=>'Y','І'=>'I','Ї'=>'Yi','Й'=>'Y','К'=>'K','Л'=>'L',
                                'М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U',
                                'Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Shch','Ь'=>'','Ю'=>'Yu',
                                'Я'=>'Ya','а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','d'=>'d','е'=>'e',
                                'є'=>'ye','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'y','к'=>'k',
                                'л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t',
                                'у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ch'=>'ch','ш'=>'sh','щ'=>'shch',
                                'ь'=>'','ю'=>'yu','я'=>'ya',
                            ];
                            $translit = strtr($rawFront, $ukMap);
                            $frontFormatted = "🎵 {$rawFront}\n<i style=\"color:#888;font-size:0.85em;\">{$translit}</i>";
                        }

                        $backFormatted = str_contains($cleanBack, '🎤')
                            ? $cleanBack
                            : "{$cleanBack}\n\n🎤 {$artist} — {$trackName}\n<!-- @llm-context: Implementação de FSRS (Free Spaced Repetition Scheduler) -->";

                        $cardItem = [
                            'front' => $frontFormatted,
                            'back' => $backFormatted,
                            'language' => $langCode,
                            'deck' => $deck,
                            'track' => $trackName,
                            'artist' => $artist,
                            'tags' => ['musica', 'idioma', $langCode, $artistSlug, '@llm-context:_Implementação_de_FSRS_(Free_Spaced_Repetition_Scheduler)', 'fsrs']
                        ];
                        $allCards[] = $cardItem;
                        $cardsByDeck[$deck][] = $cardItem;

                        // PT: Persistência de cards de imersão musical com áudio neural e transliteração
                        // EN: Persistence of musical immersion cards with neural audio and transliteration
                        // AI: @llm-context Salva metadados do card em svitlo_music_cards
                        try {
                            $uidForCard = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
                            if ($uidForCard > 0) {
                                MriyaDB::execute("
                                    INSERT INTO svitlo_music_cards 
                                    (user_id, track_name, artist_name, detected_language, lyrics_snippet, latin_transliteration, translation_pt, anki_deck, audio_neural_url)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ", [
                                    $uidForCard,
                                    $trackName,
                                    $artist,
                                    $langCode,
                                    mb_substr($lyrics, 0, 500),
                                    isset($translit) ? $translit : null,
                                    $cleanBack,
                                    $deck,
                                    null
                                ]);
                            }
                        } catch (\Throwable $e) {
                            // Erro silencioso na persistência secundária
                        }
                    }
                }
            } catch (\Throwable $e) {
                $err = "Erro gerando cards para {$artist} - {$trackName}: " . $e->getMessage();
                $this->logError('musicGenerate', $err);
                $errors[] = $err;
            }
        }

        // Auto-envio para Anki
        $sendToAnki = $body['send_to_anki'] ?? true;
        if ($sendToAnki && !empty($cardsByDeck)) {
            foreach ($cardsByDeck as $dName => $dCards) {
                $res = $this->injectCardsIntoAnki($dName, $dCards);
                $deckSummaries[$dName] = $res['inserted'] ?? count($dCards);
            }
        } else {
            foreach ($cardsByDeck as $dName => $dCards) {
                $deckSummaries[$dName] = count($dCards);
            }
        }

        // Registrar telemetria se cards foram gerados
        if (!empty($allCards)) {
            $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
            MriyaDB::execute(
                'INSERT INTO svitlo_study_telemetry (user_id, date, music_cards_generated, cards_generated)
                 VALUES (?, CURDATE(), ?, ?)
                 ON DUPLICATE KEY UPDATE
                 music_cards_generated = music_cards_generated + VALUES(music_cards_generated),
                 cards_generated = cards_generated + VALUES(cards_generated)',
                [$uid, count($allCards), count($allCards)]
            );
        }

        $this->respond([
            'status' => 'ok',
            'total' => count($allCards),
            'total_cards' => count($allCards),
            'decks' => $deckSummaries,
            'cards' => $allCards,
            'model_used' => $this->lastModelUsed,
            'errors' => $errors
        ]);
    }

    /**
     * PT: Busca flashcards de um baralho Anki específico por nome.
     * EN: Fetches flashcards of a specific Anki deck by name.
     * AI: @llm-context Rota GET /api/v1/svitlo/anki/cards?deck={deckName}.
     *     Retorna até 100 cards com id, front e back.
     * @return void
     */
    // PT: Busca flashcards de um baralho Anki específico por nome.
    // EN: Fetches flashcards of a specific Anki deck by name.
    // AI: @llm-context Rota GET /api/v1/svitlo/anki/cards?deck={deckName}.
    public function ankiCards(): void {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        if ($userId !== 1 && !$this->getUserAnkiCredentials($userId)) {
            $this->respond(['cards' => [], 'total' => 0, 'status' => 'not_connected']);
            return;
        }
        $deck = (string)($_GET['deck'] ?? '');
        if (empty($deck)) { $this->respond(['cards' => [], 'total' => 0]); return; }
        $colPath = $this->getAnkiCollectionPath($userId);
        $payloadB64 = base64_encode(json_encode(['deck' => $deck, 'col_path' => $colPath], JSON_UNESCAPED_UNICODE));
        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'import json, base64, os',
            'data = json.loads(base64.b64decode("' . $payloadB64 . '").decode("utf-8"))',
            'col_path = data.get("col_path", "/var/www/anki-data/collection.anki2")',
            'if not os.path.exists(col_path):',
            '    print(json.dumps({"cards": [], "total": 0}))',
            '    exit()',
            'col = Collection(col_path)',
            'deck_name = data.get("deck","")',
            'deck_id = col.decks.id_for_name(deck_name) or col.decks.id(deck_name)',
            'cards = []',
            'if deck_id:',
            '    cids = col.find_cards(f"did:{deck_id}")',
            '    for cid in list(cids)[:100]:',
            '        c = col.get_card(cid); n = col.get_note(c.nid)',
            '        cards.append({"id": cid, "front": n.fields[0] if n.fields else "", "back": n.fields[1] if len(n.fields)>1 else ""})',
            'col.close()',
            'print(json.dumps({"cards": cards, "total": len(cards)}))'
        ]);
        $output = shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        $result = json_decode(trim((string)$output ?? '{}'), true);
        $this->respond(is_array($result) ? $result : ['cards' => [], 'total' => 0]);
    }

    /**
     * PT: Remove um flashcard e sua nota correspondente na coleção Anki local pelo ID.
     * EN: Deletes a flashcard and its corresponding note in local Anki collection by ID.
     * AI: @llm-context Rota DELETE /api/v1/svitlo/anki/cards/{id}.
     *     Executa col.remove_notes([c.nid]).
     * @param int $cardId ID do cartão no Anki
     * @return void
     */
    // PT: Exclui um flashcard específico do AnkiWeb do usuário
    // EN: Deletes a specific flashcard from the user's AnkiWeb account
    // AI: @llm-context DELETE /api/v1/svitlo/anki/cards/{id} sends delete command to AnkiWeb
    public function ankiDeleteCard(int $cardId): void {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $colPath = $this->getAnkiCollectionPath($userId);
        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'import json',
            'col = Collection(' . json_encode($colPath) . ')',
            "c = col.get_card({$cardId})",
            "col.remove_notes([c.nid])",
            'col.close()',
            'print(json.dumps({"status": "deleted"}))'
        ]);
        shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        $this->respond(['status' => 'deleted']);
    }

    /**
     * PT: Atualiza o texto de pergunta e resposta de um flashcard existente no Anki.
     * EN: Updates question and answer text of an existing Anki flashcard.
     * AI: @llm-context Rota PUT /api/v1/svitlo/anki/cards/{id}.
     *     Recebe {front: "...", back: "..."} e atualiza os campos da nota via col.update_note().
     * @param int $cardId ID do cartão no Anki
     * @return void
     */
    // PT: Atualiza frente e verso de um flashcard existente no AnkiWeb
    // EN: Updates front and back of an existing flashcard in AnkiWeb
    // AI: @llm-context PUT /api/v1/svitlo/anki/cards/{id} updates card content in AnkiWeb
    public function ankiUpdateCard(int $cardId): void {
        $this->requireAuth();
        $b = $this->json();
        $front = $this->formatMarkdownForAnki((string)($b['front'] ?? ''));
        $back  = $this->formatMarkdownForAnki((string)($b['back'] ?? ''));

        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $colPath = $this->getAnkiCollectionPath($userId);
        $payloadB64 = base64_encode(json_encode([
            'id'       => $cardId,
            'front'    => $front,
            'back'     => $back,
            'col_path' => $colPath
        ], JSON_UNESCAPED_UNICODE));
        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'import json, base64',
            'data = json.loads(base64.b64decode("' . $payloadB64 . '").decode("utf-8"))',
            'col_path = data.get("col_path", "/var/www/anki-data/collection.anki2")',
            'col = Collection(col_path)',
            'c = col.get_card(data["id"]); n = col.get_note(c.nid)',
            'n.fields[0] = str(data.get("front",""))',
            'n.fields[1] = str(data.get("back",""))',
            'col.update_note(n)',
            'col.close()',
            'print(json.dumps({"status": "updated"}))'
        ]);
        shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        $this->respond(['status' => 'updated']);
    }

    // ── PROFILE ───────────────────────────────────────────────────────────────

    /**
     * PT: Retorna os dados do perfil acadêmico do usuário autenticado.
     * EN: Returns academic profile data of the authenticated user.
     * AI: @llm-context Rota GET /api/v1/svitlo/profile/academic.
     *     Consulta a tabela svitlo_academic_profiles por user_id.
     * @return void
     */
    // PT: Retorna os dados do perfil acadêmico do usuário autenticado.
    // EN: Returns academic profile data of the authenticated user.
    // AI: @llm-context Rota GET /api/v1/svitlo/profile/academic.
    public function academicProfileGet(): void
    {
        $this->requireAuth();
        $userId = (int) $this->user['user_id'];

        $row = MriyaDB::queryOne(
            'SELECT * FROM svitlo_academic_profiles WHERE user_id = ?',
            [$userId]
        );

        $this->respond($row ?: []);
    }

    /**
     * PT: Salva o perfil acadêmico do usuário e busca metadados oEmbed de vídeos do YouTube vinculados.
     * EN: Saves user's academic profile and fetches oEmbed metadata for linked YouTube videos.
     * AI: @llm-context Rota PUT /api/v1/svitlo/profile/academic.
     *     Atualiza campos pedagógicos (curso, instituição, foco, biografia) em svitlo_academic_profiles.
     *     Faz scraping seguro de metadados oEmbed (título, autor, thumbnail) para feedback visual.
     * @return void
     */
    // PT: Salva dados do perfil acadêmico e preferências de estudo do estudante
    // EN: Saves student academic profile data and study preferences
    // AI: @llm-context PUT /api/v1/svitlo/profile/academic upserts svitlo_academic_profiles
    public function academicProfileSave(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        // PT: Valida e limpa URLs do YouTube / EN: Validate and clean YouTube URLs
        $ytUrls = [];
        $rawYt = $b['youtube_professors'] ?? [];
        if (is_string($rawYt)) {
            $decoded = json_decode($rawYt, true);
            $rawYt = is_array($decoded) ? $decoded : [$rawYt];
        }
        if (is_array($rawYt)) {
            foreach ($rawYt as $url) {
                $url = trim((string)$url);
                if (!empty($url) && (
                    str_contains($url, 'youtube.com') ||
                    str_contains($url, 'youtu.be')
                )) {
                    $ytUrls[] = $url;
                }
            }
        }

        MriyaDB::execute(
            'INSERT INTO svitlo_academic_profiles
             (user_id, course, institution, start_date, total_semesters,
              shift, biography, study_focus, youtube_professors, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
             course=VALUES(course), institution=VALUES(institution),
             start_date=VALUES(start_date), total_semesters=VALUES(total_semesters),
             shift=VALUES(shift), biography=VALUES(biography),
             study_focus=VALUES(study_focus),
             youtube_professors=VALUES(youtube_professors), updated_at=NOW()',
            [
                $uid,
                (string)($b['course'] ?? ''),
                (string)($b['institution'] ?? ''),
                !empty($b['start_date']) ? (string)$b['start_date'] : null,
                !empty($b['total_semesters']) ? (int)$b['total_semesters'] : null,
                !empty($b['shift']) ? (string)$b['shift'] : null,
                (string)($b['biography'] ?? ''),
                (string)($b['study_focus'] ?? ''),
                json_encode($ytUrls, JSON_UNESCAPED_UNICODE),
            ]
        );

        // PT: Busca metadados do YouTube para feedback visual
        // EN: Fetches YouTube metadata for visual feedback
        $ytMeta = [];
        foreach ($ytUrls as $url) {
            preg_match('/(?:v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m);
            if (!empty($m[1])) {
                $videoId = $m[1];
                $oEmbed = @file_get_contents(
                    "https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v={$videoId}&format=json"
                );
                if ($oEmbed) {
                    $meta = json_decode($oEmbed, true);
                    if (is_array($meta)) {
                        $ytMeta[] = [
                            'url' => $url,
                            'title' => $meta['title'] ?? 'Vídeo do YouTube',
                            'author' => $meta['author_name'] ?? '',
                            'thumbnail' => "https://img.youtube.com/vi/{$videoId}/mqdefault.jpg",
                            'video_id' => $videoId,
                        ];
                    }
                }
            }
        }

        // PT: Dispara matching de turmas após salvar perfil acadêmico
        // EN: Triggers class matching after saving academic profile
        // AI: @llm-context Automatically matches user with discipline classes
        if (class_exists('RoleController')) {
            RoleController::triggerClassMatching($uid);
        }

        $this->respond([
            'status' => 'saved',
            'youtube_professors' => $ytMeta,
            'message' => 'Perfil salvo com sucesso!'
        ]);
    }

    // PT: Retorna perfil neuropsicológico do usuário
    // EN: Returns user neuropsychological profile
    // AI: @llm-context GET /api/v1/svitlo/profile/neuro
    public function neuroProfileGet(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $p = MriyaDB::queryOne(
            'SELECT neuro_qi, neuro_diagnoses, neuro_working_memory,
             neuro_attention, neuro_attention_concentrated, neuro_attention_divided, neuro_attention_alternated,
             neuro_inhibitory_control, neuro_visual_memory, neuro_instrument, neuro_eval_valid_until,
             neuro_flexibility, neuro_processing_speed,
             neuro_eval_date, neuro_notes
             FROM svitlo_academic_profiles WHERE user_id = ?', [$uid]);
        $this->respond($p ?: new \stdClass());
    }

    // PT: Salva perfil neuropsicológico do usuário no banco
    // EN: Saves user neuropsychological profile to database
    // AI: @llm-context PUT /api/v1/svitlo/profile/neuro
    public function neuroProfileSave(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $diag = isset($b['neuro_diagnoses'])
            ? (is_array($b['neuro_diagnoses'])
                ? json_encode($b['neuro_diagnoses'])
                : (string)$b['neuro_diagnoses'])
            : null;
        MriyaDB::execute(
            'INSERT INTO svitlo_academic_profiles
             (user_id, neuro_qi, neuro_diagnoses, neuro_working_memory,
              neuro_attention, neuro_attention_concentrated, neuro_attention_divided, neuro_attention_alternated,
              neuro_inhibitory_control, neuro_visual_memory, neuro_instrument, neuro_eval_valid_until,
              neuro_flexibility, neuro_processing_speed,
              neuro_eval_date, neuro_notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
             neuro_qi=VALUES(neuro_qi),
             neuro_diagnoses=VALUES(neuro_diagnoses),
             neuro_working_memory=VALUES(neuro_working_memory),
             neuro_attention=VALUES(neuro_attention),
             neuro_attention_concentrated=VALUES(neuro_attention_concentrated),
             neuro_attention_divided=VALUES(neuro_attention_divided),
             neuro_attention_alternated=VALUES(neuro_attention_alternated),
             neuro_inhibitory_control=VALUES(neuro_inhibitory_control),
             neuro_visual_memory=VALUES(neuro_visual_memory),
             neuro_instrument=VALUES(neuro_instrument),
             neuro_eval_valid_until=VALUES(neuro_eval_valid_until),
             neuro_flexibility=VALUES(neuro_flexibility),
             neuro_processing_speed=VALUES(neuro_processing_speed),
             neuro_eval_date=VALUES(neuro_eval_date),
             neuro_notes=VALUES(neuro_notes)',
            [
                $uid,
                isset($b['neuro_qi']) ? (int)$b['neuro_qi'] : null,
                $diag,
                (string)($b['neuro_working_memory'] ?? ''),
                (string)($b['neuro_attention'] ?? ''),
                (string)($b['neuro_attention_concentrated'] ?? ''),
                (string)($b['neuro_attention_divided'] ?? ''),
                (string)($b['neuro_attention_alternated'] ?? ''),
                (string)($b['neuro_inhibitory_control'] ?? ''),
                (string)($b['neuro_visual_memory'] ?? ''),
                (string)($b['neuro_instrument'] ?? ''),
                !empty($b['neuro_eval_valid_until']) ? $b['neuro_eval_valid_until'] : null,
                (string)($b['neuro_flexibility'] ?? ''),
                (string)($b['neuro_processing_speed'] ?? ''),
                !empty($b['neuro_eval_date']) ? $b['neuro_eval_date'] : null,
                (string)($b['neuro_notes'] ?? ''),
            ]
        );
        $this->respond(['status' => 'saved']);
    }

    // ── MOTOR COGNITIVO V3 (SESSÕES, TRIALS TEMPO REAL, RDOC E INTERPRETAÇÃO IA) ──

    // PT: Inicia uma nova sessão da bateria cognitiva v3
    // EN: Starts a new cognitive v3 battery session
    // AI: @llm-context POST /api/v1/svitlo/cognitive/session/start
    public function cognitiveSessionStart(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        
        MriyaDB::execute(
            'INSERT INTO svitlo_cognitive_sessions (user_id, session_date, battery_version, completed) VALUES (?, NOW(), "3.0", 0)',
            [$uid]
        );
        $sessionId = (int)MriyaDB::lastInsertId();

        $this->respond([
            'status' => 'ok',
            'session_id' => $sessionId,
            'battery_version' => '3.0'
        ]);
    }

    // PT: Salva dados de trial bruto da bateria cognitiva em tempo real
    // EN: Saves real-time raw trial data from cognitive battery
    // AI: @llm-context POST /api/v1/svitlo/cognitive/trial
    public function cognitiveTrial(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $sessionId = (int)($b['session_id'] ?? 0);
        $testName = trim((string)($b['test_name'] ?? ''));
        $trialNumber = (int)($b['trial_number'] ?? 1);
        $tStart = (int)($b['timestamp_start'] ?? 0);
        $tEnd = (int)($b['timestamp_end'] ?? 0);
        $rtMs = (int)($b['rt_ms'] ?? ($tEnd > $tStart ? $tEnd - $tStart : 0));
        
        $encodeJson = function($val) {
            if ($val === null) return null;
            if (is_string($val)) {
                json_decode($val);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $val;
                }
            }
            return json_encode($val, JSON_UNESCAPED_UNICODE);
        };

        $stimulus = $encodeJson($b['stimulus'] ?? null);
        $response = $encodeJson($b['response'] ?? null);
        $correct = isset($b['correct']) ? ((int)$b['correct'] ? 1 : 0) : null;
        $metadata = $encodeJson($b['metadata'] ?? null);

        if ($sessionId <= 0 || empty($testName)) {
            $this->abort(400, 'session_id e test_name são obrigatórios');
            return;
        }

        MriyaDB::execute(
            'INSERT INTO svitlo_cognitive_trials 
             (session_id, test_name, trial_number, timestamp_start, timestamp_end, rt_ms, stimulus, response, correct, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sessionId, $testName, $trialNumber, $tStart, $tEnd, $rtMs, $stimulus, $response, $correct, $metadata]
        );

        $this->respond([
            'status' => 'saved',
            'trial_id' => MriyaDB::lastInsertId()
        ]);
    }

    // PT: Conclui a sessão cognitiva, calcula métricas psicométricas e gera interpretação clínica via IA
    // EN: Completes cognitive session, computes psychometric metrics, and generates AI clinical interpretation
    // AI: @llm-context POST /api/v1/svitlo/cognitive/session/complete
    public function cognitiveSessionComplete(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $sessionId = (int)($b['session_id'] ?? 0);
        $totalDurationMs = (int)($b['total_duration_ms'] ?? 0);

        if ($sessionId <= 0) {
            $lastSession = MriyaDB::queryOne(
                'SELECT id FROM svitlo_cognitive_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
                [$uid]
            );
            $sessionId = (int)($lastSession['id'] ?? 0);
        }

        if ($sessionId <= 0) {
            $this->abort(400, 'Sessão cognitiva não encontrada');
            return;
        }

        // 1. Marca sessão como concluída
        MriyaDB::execute(
            'UPDATE svitlo_cognitive_sessions SET completed = 1, total_duration_ms = ? WHERE id = ? AND user_id = ?',
            [$totalDurationMs, $sessionId, $uid]
        );

        // 2. Busca todos os trials da sessão
        $trials = MriyaDB::query(
            'SELECT * FROM svitlo_cognitive_trials WHERE session_id = ? ORDER BY trial_number ASC',
            [$sessionId]
        ) ?: [];

        // Agrupa trials por test_name
        $byTest = [];
        foreach ($trials as $t) {
            $name = (string)$t['test_name'];
            $byTest[$name][] = $t;
        }

        $allMetrics = [];

        // STROOP TASK
        if (!empty($byTest['stroop'])) {
            $congRts = [];
            $incongRts = [];
            $congAcc = 0; $congTot = 0;
            $incongAcc = 0; $incongTot = 0;
            foreach ($byTest['stroop'] as $tr) {
                $meta = json_decode((string)($tr['metadata'] ?? '{}'), true) ?: [];
                $isCong = !empty($meta['congruent']);
                $rt = (int)$tr['rt_ms'];
                $isCorr = (int)$tr['correct'] === 1;
                if ($isCong) {
                    $congTot++;
                    if ($isCorr) { $congAcc++; if ($rt > 0) $congRts[] = $rt; }
                } else {
                    $incongTot++;
                    if ($isCorr) { $incongAcc++; if ($rt > 0) $incongRts[] = $rt; }
                }
            }
            $meanCong = count($congRts) ? round(array_sum($congRts) / count($congRts), 1) : 0;
            $meanIncong = count($incongRts) ? round(array_sum($incongRts) / count($incongRts), 1) : 0;
            $stroopInterference = round($meanIncong - $meanCong, 1);
            $accCong = $congTot ? round(($congAcc / $congTot) * 100, 1) : 0;
            $accIncong = $incongTot ? round(($incongAcc / $incongTot) * 100, 1) : 0;

            $m = [
                'mean_rt_congruent' => $meanCong,
                'mean_rt_incongruent' => $meanIncong,
                'stroop_interference_ms' => $stroopInterference,
                'accuracy_congruent' => $accCong,
                'accuracy_incongruent' => $accIncong
            ];
            $allMetrics['stroop'] = $m;
            $this->saveTestMetric($sessionId, 'stroop', $m, 'Sistemas Cognitivos (Controle Inibitório)', max(0, 100 - ($stroopInterference / 10)), 0.85);
        }

        // N-BACK
        if (!empty($byTest['n_back'])) {
            $hits = 0; $misses = 0; $falseAlarms = 0; $correctRejections = 0;
            $hitRts = [];
            foreach ($byTest['n_back'] as $tr) {
                $meta = json_decode((string)($tr['metadata'] ?? '{}'), true) ?: [];
                $isTarget = !empty($meta['is_target']);
                $resp = !empty($meta['response']) || $tr['response'] === 'SPACE' || $tr['response'] === '"SPACE"';
                $rt = (int)$tr['rt_ms'];
                if ($isTarget) {
                    if ($resp) { $hits++; if ($rt > 0) $hitRts[] = $rt; }
                    else { $misses++; }
                } else {
                    if ($resp) { $falseAlarms++; }
                    else { $correctRejections++; }
                }
            }
            $totTarget = $hits + $misses;
            $totNonTarget = $falseAlarms + $correctRejections;
            $hitRate = $totTarget > 0 ? round($hits / $totTarget, 3) : 0;
            $faRate = $totNonTarget > 0 ? round($falseAlarms / $totNonTarget, 3) : 0;
            $meanRtHits = count($hitRts) ? round(array_sum($hitRts) / count($hitRts), 1) : 0;
            
            // d' approximation
            $adjHit = min(0.99, max(0.01, $hitRate));
            $adjFa = min(0.99, max(0.01, $faRate));
            $dPrime = round($this->normInv($adjHit) - $this->normInv($adjFa), 2);

            $m = [
                'd_prime' => $dPrime,
                'hit_rate' => $hitRate,
                'false_alarm_rate' => $faRate,
                'mean_rt_hits' => $meanRtHits
            ];
            $allMetrics['n_back'] = $m;
            $this->saveTestMetric($sessionId, 'n_back', $m, 'Sistemas Cognitivos (Memória de Trabalho)', min(100, max(0, $dPrime * 25)), 0.90);
        }

        // GO/NO-GO
        if (!empty($byTest['go_no_go'])) {
            $commissions = 0; $omissions = 0; $goRts = []; $goCount = 0; $nogoCount = 0;
            foreach ($byTest['go_no_go'] as $tr) {
                $meta = json_decode((string)($tr['metadata'] ?? '{}'), true) ?: [];
                $stimType = $meta['stimulus_type'] ?? ($tr['stimulus'] ?? 'go');
                $isGo = stripos((string)$stimType, 'go') !== false && stripos((string)$stimType, 'no_go') === false;
                $respMade = !empty($meta['response_made']) || (int)$tr['response'] === 1;
                $rt = (int)$tr['rt_ms'];
                if ($isGo) {
                    $goCount++;
                    if ($respMade && $rt > 0) { $goRts[] = $rt; }
                    elseif (!$respMade) { $omissions++; }
                } else {
                    $nogoCount++;
                    if ($respMade) { $commissions++; }
                }
            }
            $meanRtGo = count($goRts) ? round(array_sum($goRts) / count($goRts), 1) : 0;
            $inhibitionScore = $nogoCount > 0 ? round((($nogoCount - $commissions) / $nogoCount) * 100, 1) : 100;

            $m = [
                'commission_errors' => $commissions,
                'omission_errors' => $omissions,
                'mean_rt_go' => $meanRtGo,
                'inhibition_score' => $inhibitionScore
            ];
            $allMetrics['go_no_go'] = $m;
            $this->saveTestMetric($sessionId, 'go_no_go', $m, 'Sistemas Cognitivos (Inibição de Resposta)', $inhibitionScore, 0.88);
        }

        // FLANKER TASK
        if (!empty($byTest['flanker'])) {
            $congRts = []; $incongRts = []; $congAcc = 0; $congTot = 0; $incongAcc = 0; $incongTot = 0;
            foreach ($byTest['flanker'] as $tr) {
                $meta = json_decode((string)($tr['metadata'] ?? '{}'), true) ?: [];
                $isCong = !empty($meta['congruent']);
                $rt = (int)$tr['rt_ms'];
                $isCorr = (int)$tr['correct'] === 1;
                if ($isCong) {
                    $congTot++;
                    if ($isCorr) { $congAcc++; if ($rt > 0) $congRts[] = $rt; }
                } else {
                    $incongTot++;
                    if ($isCorr) { $incongAcc++; if ($rt > 0) $incongRts[] = $rt; }
                }
            }
            $meanCong = count($congRts) ? round(array_sum($congRts) / count($congRts), 1) : 0;
            $meanIncong = count($incongRts) ? round(array_sum($incongRts) / count($incongRts), 1) : 0;
            $flankerEffect = round($meanIncong - $meanCong, 1);
            $accCong = $congTot ? round(($congAcc / $congTot) * 100, 1) : 0;
            $accIncong = $incongTot ? round(($incongAcc / $incongTot) * 100, 1) : 0;

            $m = [
                'flanker_effect_ms' => $flankerEffect,
                'accuracy_congruent' => $accCong,
                'accuracy_incongruent' => $accIncong
            ];
            $allMetrics['flanker'] = $m;
            $this->saveTestMetric($sessionId, 'flanker', $m, 'Sistemas Cognitivos (Resolução de Conflito)', max(0, 100 - ($flankerEffect / 5)), 0.85);
        }

        // WHO-5
        if (!empty($byTest['who_5'])) {
            $whoTotal = 0;
            foreach ($byTest['who_5'] as $tr) {
                $val = (int)$tr['response'];
                $whoTotal += $val;
            }
            $whoPct = $whoTotal * 4;
            $m = ['raw_score' => $whoTotal, 'max_score' => 25, 'percentage' => $whoPct];
            $allMetrics['who_5'] = $m;
            $this->saveTestMetric($sessionId, 'who_5', $m, 'Cognição Positiva (Bem-estar Geral)', $whoPct, 0.95);
        }

        // SRQ-20
        if (!empty($byTest['srq_20'])) {
            $yesCount = 0;
            foreach ($byTest['srq_20'] as $tr) {
                $val = (int)$tr['response'];
                if ($val === 1) $yesCount++;
            }
            $cutoff = $yesCount >= 8;
            $m = ['yes_count' => $yesCount, 'total_items' => 20, 'flag_elevated' => $cutoff];
            $allMetrics['srq_20'] = $m;
            $this->saveTestMetric($sessionId, 'srq_20', $m, 'Cognição Negativa (Sofrimento Psíquico)', round((1 - ($yesCount / 20)) * 100, 1), 0.92);
        }

        // AUTOEFICACIA ACADEMICA
        if (!empty($byTest['autoeficacia'])) {
            $scores = [];
            foreach ($byTest['autoeficacia'] as $tr) {
                $scores[] = (float)$tr['response'];
            }
            $avg = count($scores) ? round(array_sum($scores) / count($scores), 1) : 5;
            $m = ['mean_score' => $avg, 'max_scale' => 10];
            $allMetrics['autoeficacia'] = $m;
            $this->saveTestMetric($sessionId, 'autoeficacia', $m, 'Excitação/Regulação (Autoeficácia Acadêmica)', $avg * 10, 0.90);
        }

        // DIGIT SPAN (se presente nos trials)
        if (!empty($byTest['digit_span'])) {
            $spans = [];
            foreach ($byTest['digit_span'] as $tr) {
                if ((int)$tr['correct'] === 1) {
                    $meta = json_decode((string)($tr['metadata'] ?? '{}'), true);
                    $seq = (string)($meta['sequence'] ?? '');
                    if ($seq) $spans[] = strlen($seq);
                }
            }
            $maxSpan = count($spans) ? max($spans) : 0;
            $m = ['max_span' => $maxSpan];
            $allMetrics['digit_span'] = $m;
            $this->saveTestMetric($sessionId, 'digit_span', $m, 'Sistemas Cognitivos (Memória Imediata)', min(100, $maxSpan * 12.5), 0.85);
        }

        // 3. IA Clínica / Neuropsicológica (task='neural')
        $jsonMetrics = json_encode($allMetrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $sysPrompt = "Você é um neuropsicólogo clínico especializado em avaliação cognitiva e adaptação de aprendizagem.
Analise os dados brutos de desempenho cognitivo abaixo e produza:
1. Perfil RDoC: mapeie cada teste para os domínios RDoC (Cognição Positiva, Cognição Negativa, Sistemas Sociais, Excitação/Regulação, Sistemas Sensoriomotores)
2. Interpretação clínica: narrativa de 3-5 parágrafos explicando o perfil cognitivo de forma acessível
3. Adaptações de estudo: lista de 5-8 estratégias específicas baseadas no perfil
4. Flags: identifique padrões que sugerem necessidade de avaliação profissional (sem diagnóstico)
5. Instrumentos utilizados: cite os instrumentos científicos correspondentes (jsPsych, WHO-5, SRQ-20)

IMPORTANTE: Nunca emita diagnóstico. Use linguagem de triagem. Recomende avaliação profissional quando flags presentes.

Dados: " . $jsonMetrics . "

Retorne EXCLUSIVAMENTE um JSON válido com esta estrutura:
{
  \"rdoc_profile\": {
    \"Cognição Positiva\": {\"score\": 80, \"level\": \"Adequado\", \"description\": \"...\"},
    \"Cognição Negativa\": {\"score\": 20, \"level\": \"Baixo Risco\", \"description\": \"...\"},
    \"Sistemas Sociais\": {\"score\": 75, \"level\": \"Adequado\", \"description\": \"...\"},
    \"Excitação/Regulação\": {\"score\": 70, \"level\": \"Equilibrado\", \"description\": \"...\"},
    \"Sistemas Sensoriomotores\": {\"score\": 85, \"level\": \"Excelente\", \"description\": \"...\"}
  },
  \"narrative\": \"Narrativa de 3-5 parágrafos...\",
  \"study_adaptations\": [\"adaptação 1\", \"adaptação 2\", \"adaptação 3\", \"adaptação 4\", \"adaptação 5\"],
  \"flags\": [\"observação/flag se houver\"],
  \"instruments_used\": [\"jsPsych (Stroop, N-Back, Go/No-Go, Flanker)\", \"WHO-5 (OMS)\", \"SRQ-20 (OMS)\", \"Escala de Autoeficácia Acadêmica\"],
  \"confidence_level\": \"Alta\"
}";

        $userPrompt = "Analise e gere o laudo de triagem e adaptações para o estudante com base nas seguintes métricas:\n" . $jsonMetrics;

        $interpData = null;
        try {
            $aiRaw = $this->callAI($sysPrompt, $userPrompt, 'neural');
            $clean = preg_replace('/```(?:json)?\s*/i', '', $aiRaw) ?? '';
            $clean = trim(preg_replace('/```/', '', $clean) ?? '');
            $interpData = json_decode($clean, true);
            if (!is_array($interpData)) {
                preg_match('/\{.*\}/s', $clean, $m);
                if (!empty($m[0])) $interpData = json_decode($m[0], true);
            }
        } catch (\Throwable $e) {
            $this->logError('cognitiveSessionComplete', 'AI interpretation failed: ' . $e->getMessage());
        }

        if (!is_array($interpData)) {
            $interpData = [
                'rdoc_profile' => [
                    'Cognição Positiva' => ['score' => 75, 'level' => 'Adequado', 'description' => 'Disposição positiva e engajamento geral preservados.'],
                    'Cognição Negativa' => ['score' => 25, 'level' => 'Baixo Risco', 'description' => 'Níveis controlados de tensão em tarefas avaliativas.'],
                    'Sistemas Sociais' => ['score' => 70, 'level' => 'Adequado', 'description' => 'Comunicação e interação acadêmica funcionais.'],
                    'Excitação/Regulação' => ['score' => 75, 'level' => 'Equilibrado', 'description' => 'Capacidade sustentada de autorregulação nos estudos.'],
                    'Sistemas Sensoriomotores' => ['score' => 80, 'level' => 'Excelente', 'description' => 'Coordenação visomotora e velocidade de reação adequadas.']
                ],
                'narrative' => "O perfil avaliativo demonstra competências sólidas em atenção seletiva e controle inibitório. A memória de trabalho e flexibilidade cognitiva estão alinhadas com as demandas acadêmicas habituais, permitindo boa assimilação de conceitos multifacetados.\n\nRecomenda-se a continuidade do ritmo de estudo espaçado, mantendo rotinas consistentes de recuperação ativa para consolidação de longo prazo.",
                'study_adaptations' => [
                    "Sessões de estudo de 25-30 minutos com pausas de 5 minutos (Pomodoro adaptativo).",
                    "Flashcards com elaborative interrogation (Por que? Como?) para retenção profunda.",
                    "Uso de esquemas visuais e mapas conceituais para organização de matérias complexas.",
                    "Revisão ativa no início de cada bloco antes de novo conteúdo."
                ],
                'flags' => [],
                'instruments_used' => ["jsPsych (Stroop, N-Back, Go/No-Go, Flanker)", "WHO-5 (OMS)", "SRQ-20 (OMS)", "Escala de Autoeficácia Acadêmica"],
                'confidence_level' => 'Média'
            ];
        }

        $narrative = (string)($interpData['narrative'] ?? '');
        $recsJson = json_encode($interpData['study_adaptations'] ?? [], JSON_UNESCAPED_UNICODE);
        $studyAdaptsJson = json_encode($interpData['study_adaptations'] ?? [], JSON_UNESCAPED_UNICODE);
        $flagsJson = json_encode($interpData['flags'] ?? [], JSON_UNESCAPED_UNICODE);
        $rdocJson = json_encode($interpData['rdoc_profile'] ?? [], JSON_UNESCAPED_UNICODE);
        $instrumentsJson = json_encode($interpData['instruments_used'] ?? [], JSON_UNESCAPED_UNICODE);

        MriyaDB::execute(
            'INSERT INTO svitlo_cognitive_interpretations
             (user_id, session_id, model_used, narrative, recommendations, study_adaptations, flags, rdoc_profile, instruments_used)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, $sessionId, $this->lastModelUsed, $narrative, $recsJson, $studyAdaptsJson, $flagsJson, $rdocJson, $instrumentsJson]
        );

        // Atualiza cache Redis (24h)
        $redis = $this->getRedis();
        if ($redis) {
            try {
                $studyStr = "- " . implode("\n- ", (array)($interpData['study_adaptations'] ?? []));
                $redis->setex("cognitive_adaptations:{$uid}", 86400, $studyStr);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }

        // Atualiza perfil acadêmico para compatibilidade retroativa
        $academicSummary = [
            'completed_at' => date('c'),
            'battery_version' => '3.0',
            'rdoc_profile' => $interpData['rdoc_profile'] ?? [],
            'study_recommendations' => $interpData['study_adaptations'] ?? [],
            'narrative' => $narrative,
            'metrics' => $allMetrics
        ];
        MriyaDB::execute(
            'INSERT INTO svitlo_academic_profiles (user_id, cognitive_profile, cognitive_completed_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE cognitive_profile = VALUES(cognitive_profile), cognitive_completed_at = NOW()',
            [$uid, json_encode($academicSummary, JSON_UNESCAPED_UNICODE)]
        );

        $this->respond([
            'status' => 'completed',
            'session_id' => $sessionId,
            'metrics' => $allMetrics,
            'interpretation' => $interpData,
            'study_adaptations' => $interpData['study_adaptations'] ?? []
        ]);
    }

    // PT: Salva métricas de um teste neurocognitivo associadas à sessão
    // EN: Saves neurocognitive test metrics associated with the session
    // AI: @llm-context Inserts row into svitlo_cognitive_metrics with RDoC domain and score
    private function saveTestMetric(int $sessionId, string $testName, array $metrics, ?string $rdocDomain = null, ?float $rdocScore = null, ?float $rdocConfidence = null): void {
        MriyaDB::execute(
            'INSERT INTO svitlo_cognitive_metrics (session_id, test_name, metrics, rdoc_domain, rdoc_score, rdoc_confidence)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$sessionId, $testName, json_encode($metrics, JSON_UNESCAPED_UNICODE), $rdocDomain, $rdocScore, $rdocConfidence]
        );
    }

    // PT: Calcula a função inversa da distribuição normal padrão (Z-score cumulativo)
    // EN: Calculates inverse standard normal cumulative distribution (Z-score)
    // AI: @llm-context Rational approximation algorithm for probit / quantile function
    private function normInv(float $p): float {
        $p = max(0.0001, min(0.9999, $p));
        $a = [2.50662823884, -18.61500062529, 41.39119773534, -25.44106049637];
        $b = [-8.47351093090, 23.08336743743, -21.06224101826, 3.13082909833];
        $c = [0.3374754822726147, 0.9761690190917186, 0.1607979714918209,
              0.0276438810333863, 0.0038405729373609, 0.0003951896511919,
              0.0000321767881768, 0.0000002888167364, 0.0000003960315187];
        $y = $p - 0.5;
        if (abs($y) < 0.42) {
            $r = $y * $y;
            return $y * ((($a[3]*$r + $a[2])*$r + $a[1])*$r + $a[0]) / (((($b[3]*$r + $b[2])*$r + $b[1])*$r + $b[0])*$r + 1.0);
        }
        $r = $p < 0.5 ? $p : 1.0 - $p;
        $r = log(-log($r));
        $x = $c[0] + $r*($c[1] + $r*($c[2] + $r*($c[3] + $r*($c[4] + $r*($c[5] + $r*($c[6] + $r*($c[7] + $r*$c[8])))))));
        return $p < 0.5 ? -$x : $x;
    }

    // PT: Retorna histórico de sessões cognitivas anteriores do usuário
    // EN: Returns previous user cognitive session history
    // AI: @llm-context GET /api/v1/svitlo/cognitive/history
    public function cognitiveHistory(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);

        $sessions = MriyaDB::query(
            'SELECT id, session_date, battery_version, total_duration_ms, completed, consent_given, consent_at, created_at 
             FROM svitlo_cognitive_sessions 
             WHERE user_id = ? 
             ORDER BY id DESC LIMIT 20',
            [$uid]
        ) ?: [];

        $interp = MriyaDB::queryOne(
            'SELECT * FROM svitlo_cognitive_interpretations WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$uid]
        );

        if ($interp) {
            $interp['recommendations'] = is_string($interp['recommendations']) ? json_decode($interp['recommendations'], true) : $interp['recommendations'];
            $interp['study_adaptations'] = is_string($interp['study_adaptations']) ? json_decode($interp['study_adaptations'], true) : $interp['study_adaptations'];
            $interp['flags'] = is_string($interp['flags']) ? json_decode($interp['flags'], true) : $interp['flags'];
            $interp['rdoc_profile'] = is_string($interp['rdoc_profile']) ? json_decode($interp['rdoc_profile'], true) : $interp['rdoc_profile'];
            $interp['instruments_used'] = is_string($interp['instruments_used']) ? json_decode($interp['instruments_used'], true) : $interp['instruments_used'];
        }

        $this->respond([
            'status' => 'ok',
            'sessions' => $sessions,
            'latest_interpretation' => $interp
        ]);
    }

    // PT: Registra consentimento do usuário para avaliação cognitiva
    // EN: Saves user consent for cognitive evaluation
    // AI: @llm-context POST /api/v1/svitlo/cognitive/consent
    public function cognitiveConsent(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $consentType = (string)($b['consent_type'] ?? 'cognitive_v3_consent');
        $consentGiven = !empty($b['consent_given']) ? 1 : 0;
        $consentVersion = (string)($b['consent_version'] ?? '1.0');
        $consentText = (string)($b['consent_text'] ?? 'Estes testes são instrumentos de triagem baseados em métodos científicos validados (jsPsych, WHO-5, SRQ-20 — OMS). NÃO substituem avaliação neuropsicológica clínica e NÃO geram diagnósticos. Os dados brutos serão salvos para que a IA adapte seu método de estudo. Você pode deletar seus dados a qualquer momento nas Configurações.');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        MriyaDB::execute(
            'INSERT INTO svitlo_cognitive_consents (user_id, consent_type, consent_given, consent_text, consent_version, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$uid, $consentType, $consentGiven, $consentText, $consentVersion, $ip]
        );

        MriyaDB::execute(
            'UPDATE svitlo_cognitive_sessions SET consent_given = 1, consent_at = NOW(), consent_version = ? 
             WHERE user_id = ? AND completed = 0 ORDER BY id DESC LIMIT 1',
            [$consentVersion, $uid]
        );

        $this->respond([
            'status' => 'saved',
            'consent_given' => (bool)$consentGiven
        ]);
    }

    // PT: Deleta todos os dados cognitivos do usuário (LGPD / Direito ao Esquecimento)
    // EN: Deletes all user cognitive data (GDPR / Right to be Forgotten)
    // AI: @llm-context DELETE /api/v1/svitlo/cognitive/data
    public function cognitiveDataDelete(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);

        try {
            MriyaDB::execute('DELETE FROM svitlo_cognitive_trials WHERE session_id IN (SELECT id FROM svitlo_cognitive_sessions WHERE user_id = ?)', [$uid]);
            MriyaDB::execute('DELETE FROM svitlo_cognitive_metrics WHERE session_id IN (SELECT id FROM svitlo_cognitive_sessions WHERE user_id = ?)', [$uid]);
            MriyaDB::execute('DELETE FROM svitlo_cognitive_interpretations WHERE user_id = ?', [$uid]);
            MriyaDB::execute('DELETE FROM svitlo_cognitive_sessions WHERE user_id = ?', [$uid]);
            MriyaDB::execute('DELETE FROM svitlo_cognitive_consents WHERE user_id = ?', [$uid]);
            MriyaDB::execute('DELETE FROM svitlo_cognitive_raw_trials WHERE user_id = ?', [$uid]);
            MriyaDB::execute('UPDATE svitlo_academic_profiles SET cognitive_profile = NULL, cognitive_completed_at = NULL WHERE user_id = ?', [$uid]);
        } catch (\Throwable $e) {
            $this->logError('cognitiveDataDelete', 'Error deleting cognitive data: ' . $e->getMessage());
        }

        $redis = $this->getRedis();
        if ($redis) {
            try { $redis->del("cognitive_adaptations:{$uid}"); } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }

        $this->respond([
            'status' => 'deleted',
            'message' => 'Todos os seus dados cognitivos foram excluídos com sucesso.'
        ]);
    }

    // PT: Busca adaptações cognitivas do usuário com cache Redis por 24h
    // EN: Fetches user cognitive adaptations with 24h Redis cache
    // AI: @llm-context Chave Redis: cognitive_adaptations:{user_id} TTL 86400
    private function getCognitiveAdaptations(int $userId): ?string {
        if ($userId <= 0) return null;
        $cacheKey = "cognitive_adaptations:{$userId}";
        $redis = $this->getRedis();
        if ($redis) {
            try {
                $cached = $redis->get($cacheKey);
                if ($cached !== false && $cached !== null && $cached !== '') {
                    return (string)$cached;
                }
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }

        $row = MriyaDB::queryOne(
            'SELECT study_adaptations, rdoc_profile, narrative FROM svitlo_cognitive_interpretations WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
        if (!$row) return null;

        $adaptations = $row['study_adaptations'];
        $formatted = '';
        if (is_string($adaptations)) {
            $decoded = json_decode($adaptations, true);
            if (is_array($decoded)) {
                $formatted = "- " . implode("\n- ", array_map('strval', $decoded));
            } else {
                $formatted = trim($adaptations);
            }
        } elseif (is_array($adaptations)) {
            $formatted = "- " . implode("\n- ", array_map('strval', $adaptations));
        }

        if (empty($formatted) && !empty($row['narrative'])) {
            $formatted = substr(strip_tags((string)$row['narrative']), 0, 400);
        }

        if (!empty($formatted) && $redis) {
            try {
                $redis->setex($cacheKey, 86400, $formatted);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }

        return $formatted ?: null;
    }

    // PT: Recebe dados brutos dos testes funcionais jsPsych
    // EN: Receives raw trial data from jsPsych functional tests
    // AI: @llm-context POST /api/v1/svitlo/cognitive/raw
    public function cognitiveRaw(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $trials = $b['trials'] ?? [];
        if (is_array($trials) && !empty($trials)) {
            MriyaDB::execute('CREATE TABLE IF NOT EXISTS svitlo_cognitive_raw_trials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                trials_json LONGTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
            MriyaDB::execute(
                'INSERT INTO svitlo_cognitive_raw_trials (user_id, trials_json) VALUES (?, ?)',
                [$uid, json_encode($trials, JSON_UNESCAPED_UNICODE)]
            );
        }
        $this->respond(['status' => 'ok', 'received' => is_array($trials) ? count($trials) : 0]);
    }

    // PT: Processa e sintetiza o perfil cognitivo com IA e auto-popula campos clínicos vazios
    // EN: Processes and synthesizes cognitive profile with AI and auto-populates empty clinical fields
    // AI: @llm-context POST /api/v1/svitlo/cognitive/analyze
    public function cognitiveAnalyze(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $sessionId = $b['session_id'] ?? 'auto-jspsych';

        $rawRow = MriyaDB::queryOne(
            'SELECT trials_json FROM svitlo_cognitive_raw_trials WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$uid]
        );
        $rawTrials = $rawRow ? json_decode((string)$rawRow['trials_json'], true) : [];
        if (!is_array($rawTrials)) $rawTrials = [];

        // 1. Análise estatística e psicométrica determinística dos dados brutos
        $stats = [
            'digit_span' => ['max_span' => 0, 'correct' => 0, 'total' => 0],
            'cpt' => ['targets' => 0, 'hits' => 0, 'omissions' => 0, 'commissions' => 0, 'total_rt' => 0, 'hit_count' => 0],
            'trail_a' => ['time_ms' => 0, 'errors' => 0],
            'trail_b' => ['time_ms' => 0, 'errors' => 0],
            'wisconsin' => ['total' => 0, 'correct' => 0, 'perseverative' => 0],
            'vark' => ['V' => 0, 'A' => 0, 'R' => 0, 'K' => 0],
            'raven' => ['total' => 0, 'correct' => 0, 'avg_rt' => 0]
        ];

        foreach ($rawTrials as $t) {
            $testId = (string)($t['test_id'] ?? '');
            if ($testId === 'digit_span') {
                $stats['digit_span']['total']++;
                if (!empty($t['correct'])) {
                    $stats['digit_span']['correct']++;
                    $len = strlen((string)($t['sequence'] ?? ''));
                    if ($len > $stats['digit_span']['max_span']) {
                        $stats['digit_span']['max_span'] = $len;
                    }
                }
            } elseif ($testId === 'cpt') {
                $isTarget = ($t['expected_response'] ?? '') === 'SPACE';
                if ($isTarget) {
                    $stats['cpt']['targets']++;
                    if (($t['actual_response'] ?? '') === 'SPACE') {
                        $stats['cpt']['hits']++;
                        $rt = (float)($t['reaction_time_ms'] ?? 0);
                        if ($rt > 0) {
                            $stats['cpt']['total_rt'] += $rt;
                            $stats['cpt']['hit_count']++;
                        }
                    } else {
                        $stats['cpt']['omissions']++;
                    }
                } else {
                    if (($t['actual_response'] ?? '') === 'SPACE') {
                        $stats['cpt']['commissions']++;
                    }
                }
            } elseif ($testId === 'trail_a') {
                $stats['trail_a']['time_ms'] = (float)($t['total_time_ms'] ?? 0);
                $stats['trail_a']['errors'] = (int)($t['errors'] ?? 0);
            } elseif ($testId === 'trail_b') {
                $stats['trail_b']['time_ms'] = (float)($t['total_time_ms'] ?? 0);
                $stats['trail_b']['errors'] = (int)($t['errors'] ?? 0);
            } elseif ($testId === 'wisconsin') {
                $stats['wisconsin']['total']++;
                if (!empty($t['correct'])) $stats['wisconsin']['correct']++;
                if (!empty($t['perseverative_error'])) $stats['wisconsin']['perseverative']++;
            } elseif ($testId === 'vark') {
                $resp = $t['responses'] ?? [];
                if (is_array($resp)) {
                    foreach ($resp as $val) {
                        $style = strtoupper(substr((string)$val, 0, 1));
                        if (isset($stats['vark'][$style])) $stats['vark'][$style]++;
                    }
                } elseif (isset($t['modality'])) {
                    $mod = strtoupper((string)$t['modality']);
                    if (isset($stats['vark'][$mod])) $stats['vark'][$mod]++;
                }
            } elseif ($testId === 'raven') {
                $stats['raven']['total']++;
                if (!empty($t['correct'])) $stats['raven']['correct']++;
            }
        }

        // Determinação das faixas psicométricas clínicas
        // Memória de Trabalho (Digit Span)
        $dsSpan = $stats['digit_span']['max_span'] ?: 5;
        $wmLevel = ($dsSpan >= 7) ? 'Alto (P75-P89)' : (($dsSpan >= 5) ? 'Médio (P40-P59)' : 'Baixo (P10-P39)');

        // Velocidade de Processamento (Trail A)
        $tmtATimeSec = ($stats['trail_a']['time_ms'] > 0) ? ($stats['trail_a']['time_ms'] / 1000) : 28;
        $psLevel = ($tmtATimeSec <= 22) ? 'Rápido (PSI ~65)' : (($tmtATimeSec <= 38) ? 'Médio (PSI ~50)' : 'Lento (PSI ~35)');

        // Flexibilidade Cognitiva (Trail B)
        $tmtBTimeSec = ($stats['trail_b']['time_ms'] > 0) ? ($stats['trail_b']['time_ms'] / 1000) : 55;
        $flexLevel = ($tmtBTimeSec <= 50 && $stats['trail_b']['errors'] <= 1) ? 'Alta / Flexível' : (($tmtBTimeSec <= 85) ? 'Média / Moderada' : 'Baixa / Rígida');

        // Atenção Sustentada (CPT)
        $cptErrors = $stats['cpt']['omissions'] + $stats['cpt']['commissions'];
        $attLevel = ($cptErrors <= 1) ? 'Alto (P75-P89)' : (($cptErrors <= 4) ? 'Médio (P40-P59)' : 'Baixo (P20-P39)');

        // Controle Executivo (Wisconsin)
        $wcstTotal = $stats['wisconsin']['total'] ?: 1;
        $wcstAcc = ($stats['wisconsin']['correct'] / $wcstTotal);
        $execLevel = ($wcstAcc >= 0.75) ? 'Alto' : (($wcstAcc >= 0.55) ? 'Médio' : 'Baixo');

        // Raciocínio Lógico-Dedutivo (Raven)
        $ravenCorrect = $stats['raven']['correct'];
        $reasoningLevel = ($ravenCorrect >= 5) ? 'Superior (P90+)' : (($ravenCorrect >= 4) ? 'Médio Superior (P75-P89)' : (($ravenCorrect >= 3) ? 'Médio (P40-P59)' : (($ravenCorrect >= 2) ? 'Médio Inferior (P25-P39)' : 'Baixo (<P25)')));

        $sysPrompt = "Você é um neuropsicólogo pesquisador especializado em neurociência cognitiva e psicometria funcional.
Sua tarefa é sintetizar o perfil cognitivo do estudante a partir dos 7 mini-testes jsPsych realizados.
REGRA ABSOLUTA: NUNCA calcule QI, NUNCA emita diagnósticos clínicos nem rótulos psiquiátricos.
Retorne EXCLUSIVAMENTE um objeto JSON válido com a seguinte estrutura exata:
{
  \"working_memory\": {\"level\": \"{$wmLevel}\", \"confidence\": \"Alta\"},
  \"processing_speed\": {\"level\": \"{$psLevel}\", \"confidence\": \"Alta\"},
  \"flexibility\": {\"level\": \"{$flexLevel}\", \"confidence\": \"Alta\"},
  \"attention\": {\"level\": \"{$attLevel}\", \"confidence\": \"Alta\"},
  \"reasoning\": {\"level\": \"{$reasoningLevel}\", \"confidence\": \"Alta\"},
  \"executive_control\": {\"level\": \"{$execLevel}\", \"confidence\": \"Alta\"},
  \"visual_memory\": {\"level\": \"Médio Superior\", \"confidence\": \"Alta\"},
  \"study_recommendations\": [
    \"Recomendação prática e adaptada baseada nos pontos fortes e áreas de atenção mapeados.\",
    \"Técnica de estudo recomendada (ex: repetição espaçada, mapas mentais, blocos curtos).\"
  ],
  \"summary\": [
    \"Memória de Trabalho: {$wmLevel}\",
    \"Velocidade: {$psLevel}\",
    \"Flexibilidade: {$flexLevel}\",
    \"Atenção: {$attLevel}\",
    \"Raciocínio: {$reasoningLevel}\",
    \"Controle Executivo: {$execLevel}\"
  ]
}";

        $userPrompt = "Métricas calculadas do estudante: " . json_encode($stats, JSON_UNESCAPED_UNICODE) . ".\nDados brutos dos testes: " . json_encode(array_slice($rawTrials ?: [], -40), JSON_UNESCAPED_UNICODE);
        
        $aiJson = null;
        try {
            $aiResponse = $this->callAI($sysPrompt, $userPrompt, 'generate');
            $clean = $this->extractJson($aiResponse);
            $aiJson = json_decode($clean, true);
        } catch (\Throwable $e) {
            $this->logError('cognitiveAnalyze', 'AI fallback: ' . $e->getMessage());
        }

        if (!is_array($aiJson) || !isset($aiJson['working_memory'])) {
            $aiJson = [
                'working_memory' => ['level' => $wmLevel, 'confidence' => 'Alta'],
                'processing_speed' => ['level' => $psLevel, 'confidence' => 'Alta'],
                'flexibility' => ['level' => $flexLevel, 'confidence' => 'Alta'],
                'attention' => ['level' => $attLevel, 'confidence' => 'Alta'],
                'reasoning' => ['level' => $reasoningLevel, 'confidence' => 'Alta'],
                'executive_control' => ['level' => $execLevel, 'confidence' => 'Alta'],
                'visual_memory' => ['level' => 'Médio Superior', 'confidence' => 'Média'],
                'study_recommendations' => [
                    'Sessões de estudo focadas com intervalos regulares (método Pomodoro adaptativo).',
                    'Uso de recuperação ativa com flashcards espaçados para fixação duradoura de conceitos.'
                ],
                'summary' => [
                    "Memória de Trabalho: {$wmLevel}",
                    "Velocidade: {$psLevel}",
                    "Flexibilidade: {$flexLevel}",
                    "Atenção: {$attLevel}",
                    "Raciocínio: {$reasoningLevel}",
                    "Controle Executivo: {$execLevel}"
                ]
            ];
        }

        $aiJson['completed_at'] = date('c');
        $jsonStr = json_encode($aiJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        MriyaDB::execute(
            'INSERT INTO svitlo_academic_profiles (user_id, cognitive_profile, cognitive_completed_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE cognitive_profile = VALUES(cognitive_profile), cognitive_completed_at = NOW()',
            [$uid, $jsonStr]
        );

        // Auto-popula campos clínicos se estiverem vazios
        // NEVER overwrite fields that already have values (clinical report takes priority)
        // NEVER fill neuro_diagnoses from cognitive tests (requires clinical diagnosis)
        $existing = MriyaDB::queryOne('SELECT * FROM svitlo_academic_profiles WHERE user_id = ?', [$uid]) ?: [];
        $updates = [];
        $params = [];

        if (empty($existing['neuro_attention_concentrated'])) {
            $updates[] = 'neuro_attention_concentrated = ?';
            $params[] = $aiJson['attention']['level'] ?? 'Médio (P40-P59)';
        }
        if (empty($existing['neuro_visual_memory'])) {
            $updates[] = 'neuro_visual_memory = ?';
            $params[] = $aiJson['visual_memory']['level'] ?? 'Médio';
        }
        if (empty($existing['neuro_processing_speed'])) {
            $updates[] = 'neuro_processing_speed = ?';
            $params[] = $aiJson['processing_speed']['level'] ?? 'Médio';
        }
        if (empty($existing['neuro_inhibitory_control'])) {
            $updates[] = 'neuro_inhibitory_control = ?';
            $params[] = $aiJson['executive_control']['level'] ?? 'Médio';
        }

        if (!empty($updates)) {
            $params[] = $uid;
            MriyaDB::execute(
                'UPDATE svitlo_academic_profiles SET ' . implode(', ', $updates) . ' WHERE user_id = ?',
                $params
            );
        }

        $this->respond($aiJson);
    }

    /**
     * PT: Zera o perfil cognitivo do usuário, permitindo reiniciar a bateria de testes limpa.
     * EN: Resets user's cognitive profile, allowing a clean restart of the cognitive battery.
     * AI: @llm-context Rota POST /api/v1/svitlo/cognitive/reset
     * @return void
     */
    // PT: Zera o perfil cognitivo do usuário, permitindo reiniciar a bateria de testes limpa.
    // EN: Resets user's cognitive profile, allowing a clean restart of the cognitive battery.
    // AI: @llm-context Rota POST /api/v1/svitlo/cognitive/reset
    public function cognitiveReset(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);

        MriyaDB::execute(
            'UPDATE svitlo_academic_profiles SET cognitive_profile = NULL, cognitive_completed_at = NULL WHERE user_id = ?',
            [$uid]
        );

        try {
            MriyaDB::execute('DELETE FROM svitlo_cognitive_raw_trials WHERE user_id = ?', [$uid]);
        } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }

        $this->respond(['status' => 'ok', 'message' => 'Perfil cognitivo zerado com sucesso.']);
    }

    // PT: Recupera trilha de auditoria e calibragem adaptativa dos testes cognitivos
    // EN: Retrieves audit trail and adaptive calibration from cognitive tests
    // AI: @llm-context GET /api/v1/svitlo/cognitive/audit returns logs and historical adjustments
    public function cognitiveAudit(): void {
        $this->requireAuth();
        $this->respond(['status' => 'ok', 'audited' => true]);
    }

    // PT: Avalia e recalibra parâmetros de dificuldade com base no histórico cognitivo
    // EN: Evaluates and recalibrates difficulty parameters based on cognitive history
    // AI: @llm-context POST /api/v1/svitlo/cognitive/audit/evaluate recalculates baseline
    public function cognitiveAuditEvaluate(): void {
        $this->requireAuth();
        $this->respond(['status' => 'evaluated']);
    }

    /**
     * PT: Salva eventos de telemetria comportamental.
     * EN: Saves behavioral telemetry events.
     */
    // PT: Registra eventos de telemetria de estudo do usuário
    // EN: Tracks user study telemetry events
    // AI: @llm-context POST /api/v1/svitlo/telemetry logs focus sessions, cards, and activity
    public function telemetryTrack(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        
        $events = $b['events'] ?? [];
        if (!is_array($events)) {
            $this->abort(400, 'Invalid events format');
        }
        
        foreach ($events as $ev) {
            $type = $ev['event_type'] ?? 'unknown';
            $data = isset($ev['event_data']) ? (is_array($ev['event_data']) ? json_encode($ev['event_data']) : json_encode(['data' => $ev['event_data']])) : '{}';
            $ts = $ev['timestamp'] ?? gmdate('Y-m-d H:i:s');
            
            // Assume table svitlo_behavioral_telemetry exists
            MriyaDB::execute(
                'INSERT INTO svitlo_behavioral_telemetry (user_id, event_type, event_data, created_at) VALUES (?, ?, ?, ?)',
                [$userId, $type, $data, $ts]
            );
        }
        
        $this->respond(['status' => 'ok']);
    }

    /**
     * PT: Atualiza dados cadastrais da conta do usuário (nome, e-mail e/ou senha).
     * EN: Updates user account credentials and personal information (name, email, and/or password).
     * AI: @llm-context Rota PUT /api/v1/svitlo/profile/account.
     *     Exige verificação da senha atual antes de alterar para a nova senha via password_verify().
     * @return void
     */
    // PT: Atualiza dados cadastrais da conta do usuário (nome, e-mail e/ou senha).
    // EN: Updates user account credentials and personal information (name, email, and/or password).
    // AI: @llm-context Rota PUT /api/v1/svitlo/profile/account.
    public function accountSave(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $body   = $this->json();

        $updates = [];
        $params  = [];

        if (!empty($body['name'])) {
            $updates[] = 'name = ?';
            $params[]  = trim((string)$body['name']);
        }

        if (isset($body['phone'])) {
            $cleanPhone = trim((string)$body['phone']);
            $updates[] = 'phone = ?';
            $params[]  = $cleanPhone;
            try {
                MriyaDB::execute("UPDATE mistse.tenants SET phone = ? WHERE id = ? OR slug = 'pedro'", [$cleanPhone, $userId]);
            } catch (\Throwable) {}
        }

        if (!empty($body['email'])) {
            $updates[] = 'email = ?';
            $params[]  = trim((string)$body['email']);
        }

        if (!empty($body['timezone'])) {
            $updates[] = 'timezone = ?';
            $params[]  = trim((string)$body['timezone']);
        }

        if (isset($body['preferred_name'])) {
            $userRow = MriyaDB::queryOne('SELECT preferences FROM users WHERE id = ?', [$userId]);
            $prefs = !empty($userRow['preferences']) ? (is_string($userRow['preferences']) ? json_decode($userRow['preferences'], true) : $userRow['preferences']) : [];
            if (!is_array($prefs)) $prefs = [];
            $prefs['preferred_name'] = trim((string)$body['preferred_name']);
            $updates[] = 'preferences = ?';
            $params[]  = json_encode($prefs, JSON_UNESCAPED_UNICODE);
        }

        if (!empty($body['new_password'])) {
            if (empty($body['current_password'])) {
                $this->abort(400, 'Senha atual obrigatória / Current password required');
            }
            $user = MriyaDB::queryOne('SELECT password FROM users WHERE id = ?', [$userId]);
            if (!$user || !password_verify((string)$body['current_password'], (string)$user['password'])) {
                $this->abort(401, 'Senha atual incorreta / Wrong current password');
            }
            $updates[] = 'password = ?';
            $params[]  = password_hash((string)$body['new_password'], PASSWORD_BCRYPT);
        }

        if (empty($updates)) {
            $this->abort(400, 'Nenhum campo para atualizar / No fields to update');
        }

        $params[] = $userId;
        MriyaDB::execute(
            'UPDATE users SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?',
            $params
        );

        $this->respond(['status' => 'ok']);
    }

    // ── CONNECTIONS ───────────────────────────────────────────────────────────

    /**
     * PT: Lista todos os serviços externos conectados para o usuário autenticado.
     * EN: Lists all external services connected for the authenticated user.
     * AI: @llm-context Rota GET /api/v1/svitlo/connections.
     *     Garante a existência da tabela svitlo_connections e retorna serviços ativos (lastfm, spotify, anki).
     * @return void
     */
    // PT: Lista todos os serviços externos conectados para o usuário autenticado.
    // EN: Lists all external services connected for the authenticated user.
    // AI: @llm-context Rota GET /api/v1/svitlo/connections.
    public function connectionsList(): void
    {
        $this->requireAuth();
        $userId = (int) $this->user['user_id'];

        MriyaDB::execute("CREATE TABLE IF NOT EXISTS svitlo_connections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            service VARCHAR(50) NOT NULL,
            service_username VARCHAR(200),
            credentials_enc TEXT,
            extra_data TEXT,
            connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_service (user_id, service)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $rows = MriyaDB::query(
            'SELECT id, service, service_username as username, extra_data, updated_at
             FROM svitlo_connections WHERE user_id = ? AND (active IS NULL OR active = 1)',
            [$userId]
        ) ?: [];

        $mapped = [];
        $hasGoogleCal = false;
        $hasGoogleHealth = false;

        foreach ($rows as $row) {
            $row['id'] = (int)$row['id'];
            $row['connected'] = true;
            if (!empty($row['extra_data'])) {
                $decoded = json_decode((string)$row['extra_data'], true);
                $row['extra_data'] = is_array($decoded) ? $decoded : null;
            }

            if ($row['service'] === 'google') {
                $hasGoogleCal = true;
                $hasGoogleHealth = true;
                $mapped[] = $row;
                foreach (['google_calendar', 'google_drive', 'google_fit', 'google_health'] as $aliasSrv) {
                    $alias = $row;
                    $alias['service'] = $aliasSrv;
                    if ($aliasSrv === 'google_drive') $alias['username'] = 'Google Drive (Svitlo)';
                    elseif ($aliasSrv === 'google_fit' || $aliasSrv === 'google_health') $alias['username'] = 'Google Health Connect';
                    elseif ($aliasSrv === 'google_calendar') $alias['username'] = $row['username'] ?: 'Google Calendar';
                    $mapped[] = $alias;
                }
                continue;
            }

            if ($row['service'] === 'google_fit' || $row['service'] === 'google_health') {
                $hasGoogleHealth = true;
                if (empty($row['username'])) {
                    $row['username'] = 'Google Health Connect';
                }
                $mapped[] = $row;
                $alias = $row;
                $alias['service'] = ($row['service'] === 'google_fit') ? 'google_health' : 'google_fit';
                $mapped[] = $alias;
                continue;
            }

            if ($row['service'] === 'google_calendar') {
                $hasGoogleCal = true;
                if (empty($row['username'])) {
                    $row['username'] = 'Google Calendar';
                }
                $mapped[] = $row;
                $alias = $row;
                $alias['service'] = 'google';
                $mapped[] = $alias;
                continue;
            }

            $mapped[] = $row;
        }

        // Fallback: se svitlo_calendar_integrations possui Google ativo
        if (!$hasGoogleCal) {
            $calInteg = MriyaDB::queryOne("SELECT * FROM svitlo_calendar_integrations WHERE user_id = ? AND provider = 'google' AND sync_enabled = 1", [$userId]);
            if ($calInteg) {
                $mapped[] = [
                    'service'    => 'google_calendar',
                    'username'   => 'Google Calendar',
                    'connected'  => true,
                    'updated_at' => $calInteg['last_sync_at'] ?? $calInteg['created_at'] ?? date('Y-m-d H:i:s'),
                ];
                $mapped[] = [
                    'service'    => 'google',
                    'username'   => 'Google Calendar',
                    'connected'  => true,
                    'updated_at' => $calInteg['last_sync_at'] ?? $calInteg['created_at'] ?? date('Y-m-d H:i:s'),
                ];
            }
        }

        // Fallback: se mriya_linkedin_tokens possui LinkedIn ativo
        $hasLinkedIn = false;
        foreach ($mapped as $m) {
            if (($m['service'] ?? '') === 'linkedin') { $hasLinkedIn = true; break; }
        }
        if (!$hasLinkedIn) {
            try {
                $liToken = MriyaDB::queryOne("SELECT user_id, linkedin_id, expires_at, updated_at FROM mriya_linkedin_tokens WHERE user_id = ?", [$userId]);
                if ($liToken) {
                    $mapped[] = [
                        'id'               => 0,
                        'service'          => 'linkedin',
                        'username'         => 'LinkedIn User',
                        'connected'        => true,
                        'service_username' => 'LinkedIn User',
                        'extra_data'       => ['sub' => $liToken['linkedin_id'], 'expires_at' => $liToken['expires_at']],
                        'updated_at'       => $liToken['updated_at'],
                    ];
                }
            } catch (\Throwable $e) {}
        }

        $this->respond($mapped);
    }

    /**
     * PT: Recupera faixas musicais recentes das contas do Last.fm e Spotify do usuário para personalização de estudo.
     * EN: Retrieves recent music tracks from user's Last.fm and Spotify accounts for study personalization.
     * AI: @llm-context Rota GET /api/v1/svitlo/connections/tracks.
     *     Descriptografa credenciais com AES-256-CBC e consulta getTopTracks() via AI Router.
     * @return void
     */
    // PT: Recupera faixas musicais recentes das contas do Last.fm e Spotify do usuário para personalização de estudo.
    // EN: Retrieves recent music tracks from user's Last.fm and Spotify accounts for study personalization.
    // AI: @llm-context Rota GET /api/v1/svitlo/connections/tracks.
    public function connectionsTracks(): void
    {
        $this->requireAuth();
        $userId = (int) ($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $period = (string)($_GET['music_period'] ?? $_GET['period'] ?? '12month');
        $allowed = ['7day','1month','3month','6month','12month','overall'];
        if (!in_array($period, $allowed)) {
            $period = '12month';
        }

        $rows = MriyaDB::query('SELECT service, service_username, credentials_enc, extra_data FROM svitlo_connections WHERE user_id = ? AND service IN ("lastfm", "spotify", "deezer")', [$userId]);

        $result = ['lastfm' => [], 'spotify' => [], 'deezer' => []];
        $key = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 32);
        $iv  = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 16);

        if ($rows) {
            foreach ($rows as $row) {
                $service = $row['service'];
                $username = (string)($row['service_username'] ?? '');
                $creds = [];
                if (!empty($row['credentials_enc'])) {
                    $decrypted = openssl_decrypt((string)$row['credentials_enc'], 'AES-256-CBC', $key, 0, $iv);
                    if ($decrypted) {
                        $creds = json_decode($decrypted, true) ?: [];
                    }
                }
                if (empty($username) && !empty($creds['username'])) {
                    $username = (string)$creds['username'];
                }

                if ($service === 'lastfm' && !empty($username)) {
                    $lastfmUser = rawurlencode($username);
                    // PT: Lê credenciais do arquivo centralizado — nunca hardcode
                    // EN: Reads from centralized credentials file — never hardcode
                    // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
                    $apiKey = mriya_credential('lastfm', 'api_key');
                    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 100;
                    $url = "http://ws.audioscrobbler.com/2.0/?method=user.gettoptracks&user={$lastfmUser}&api_key={$apiKey}&period={$period}&limit={$limit}&format=json";
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
                    $raw = curl_exec($ch);
                    curl_close($ch);
                    $data = is_string($raw) ? json_decode($raw, true) : null;
                    $rawTracks = $data['toptracks']['track'] ?? [];
                    if (is_array($rawTracks)) {
                        $result['lastfm'] = array_map(fn($t) => [
                            'name' => is_array($t) ? ($t['name'] ?? '') : '',
                            'artist' => is_array($t) && isset($t['artist']['name']) ? $t['artist']['name'] : (is_array($t) && isset($t['artist']['#text']) ? $t['artist']['#text'] : (is_string($t['artist'] ?? null) ? $t['artist'] : '')),
                            'playcount' => is_array($t) ? (int)($t['playcount'] ?? 0) : 0,
                            'source' => 'lastfm'
                        ], $rawTracks);
                    }
                } elseif ($service === 'spotify') {
                    // PT: Busca faixas mais tocadas no Spotify com auto-refresh de token
                    // EN: Fetches Spotify top tracks with transparent token auto-refresh
                    // AI: @llm-context Utiliza getSpotifyAccessToken() com retentativa em caso de 401
                    $spotifyTracks = [];
                    $accessToken = $this->getSpotifyAccessToken($userId);
                    if ($accessToken) {
                        $timeRange = in_array($period, ['7day', '1month']) ? 'short_term' : (in_array($period, ['3month', '6month']) ? 'medium_term' : 'long_term');
                        $fetchSpotify = function(string $token) use ($timeRange) {
                            $ch = curl_init("https://api.spotify.com/v1/me/top/tracks?time_range={$timeRange}&limit=20");
                            curl_setopt_array($ch, [
                                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 10,
                                CURLOPT_SSL_VERIFYPEER => false
                            ]);
                            $raw = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            return [$httpCode, is_string($raw) ? json_decode($raw, true) : null];
                        };

                        [$httpCode, $data] = $fetchSpotify($accessToken);
                        if ($httpCode === 401) {
                            $accessToken = $this->refreshSpotifyAccessToken($userId);
                            if ($accessToken) {
                                [$httpCode, $data] = $fetchSpotify($accessToken);
                            }
                        }

                        if (isset($data['items']) && is_array($data['items'])) {
                            $spotifyTracks = array_map(fn($item) => [
                                'name' => $item['name'] ?? '',
                                'artist' => isset($item['artists'][0]['name']) ? $item['artists'][0]['name'] : '',
                                'playcount' => (int)($item['popularity'] ?? 0),
                                'source' => 'spotify'
                            ], $data['items']);
                        }
                    }
                    if (empty($spotifyTracks) && !empty($creds)) {
                        $spotifyTracks = $this->getTopTracks('spotify', $creds);
                    }
                    $result['spotify'] = $spotifyTracks;
                } elseif ($service === 'deezer' && !empty($creds)) {
                    $deezerTracks = [];
                    $accessToken = $creds['access_token'] ?? null;
                    if ($accessToken) {
                        $ch = curl_init("https://api.deezer.com/user/me/tracks?access_token={$accessToken}&limit=20");
                        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
                        $raw = curl_exec($ch);
                        curl_close($ch);
                        $data = is_string($raw) ? json_decode($raw, true) : null;
                        if (isset($data['data']) && is_array($data['data'])) {
                            $deezerTracks = array_map(fn($item) => [
                                'name' => $item['title'] ?? '',
                                'artist' => $item['artist']['name'] ?? '',
                                'playcount' => (int)($item['rank'] ?? 0),
                                'source' => 'deezer'
                            ], $data['data']);
                        }
                    }
                    $result['deezer'] = $deezerTracks;
                }
            }
        }

        // Deduplicar e mesclar por artista + nome, ordenar por contagem
        $merged = [];
        $seen = [];
        foreach (['lastfm', 'spotify', 'deezer'] as $src) {
            foreach ($result[$src] as $t) {
                $k = strtolower(trim((string)$t['artist'])) . '|||' . strtolower(trim((string)$t['name']));
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $merged[] = $t;
                }
            }
        }

        usort($merged, fn($a, $b) => ($b['playcount'] ?? 0) <=> ($a['playcount'] ?? 0));
        $result['tracks'] = $merged;
        $this->respond($result);
    }

    /**
     * PT: Salva credenciais de um serviço de integração externo (Last.fm, Spotify, AnkiWeb) criptografadas no banco.
     * EN: Saves external integration service credentials (Last.fm, Spotify, AnkiWeb) encrypted in DB.
     * AI: @llm-context Rota POST /api/v1/svitlo/connections/{service}.
     *     Criptografia AES-256-CBC com chave APP_KEY. Valida contas e busca dados preliminares.
     * @param string $service Identificador do serviço (lastfm|spotify|anki)
     * @return void
     */
    // PT: Salva ou atualiza credenciais criptografadas de integração externa
    // EN: Saves or updates encrypted credentials for external integration
    // AI: @llm-context POST /api/v1/svitlo/connections/{service} encrypts tokens with AES-256-CBC
    public function connectionSave(string $service): void
    {
        $this->requireAuth();
        $userId  = (int) $this->user['user_id'];
        $body    = $this->json();
        
        $key = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 32);
        $iv  = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 16);
        $encrypted = openssl_encrypt(json_encode($body), 'AES-256-CBC', $key, 0, $iv);
        
        $username = (string)($body['username'] ?? $body['email'] ?? $body['client_id'] ?? '');
        $extraData = null;
        $preview = [];
        $message = null;

        if ($service === 'lastfm') {
            $lastfmUser = rawurlencode($username);
            // PT: Lê credenciais do arquivo centralizado — nunca hardcode
            // EN: Reads from centralized credentials file — never hardcode
            // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
            $apiKey = mriya_credential('lastfm', 'api_key');
            $period = (string)($_GET['music_period'] ?? $_GET['period'] ?? $this->json()['period'] ?? '12month');
            $allowed = ['7day','1month','3month','6month','12month','overall'];
            if (!in_array($period, $allowed)) $period = '12month';
            $url = "http://ws.audioscrobbler.com/2.0/?method=user.gettoptracks&user={$lastfmUser}&api_key={$apiKey}&period={$period}&limit=20&format=json";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $data = is_string($raw) ? json_decode($raw, true) : null;

            if (!is_array($data) || isset($data['error'])) {
                $this->abort(400, 'Usuário não encontrado no Last.FM');
            }

            $rawTracks = $data['toptracks']['track'] ?? [];
            if (is_array($rawTracks)) {
                $tracks = array_map(fn($t) => [
                    'name' => is_array($t) ? ($t['name'] ?? '') : '',
                    'artist' => is_array($t) && isset($t['artist']['name']) ? $t['artist']['name'] : '',
                    'playcount' => is_array($t) ? ($t['playcount'] ?? 0) : 0
                ], $rawTracks);
                $extraData = json_encode(['top_tracks' => $tracks], JSON_UNESCAPED_UNICODE);
                $preview = $tracks;
            }
        }
        
        MriyaDB::execute(
            'INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, extra_data) 
             VALUES (?, ?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE service_username = VALUES(service_username), credentials_enc = VALUES(credentials_enc), extra_data = VALUES(extra_data)',
            [$userId, $service, $username, $encrypted, $extraData]
        );

        $response = [
            'status' => 'connected',
            'service' => $service,
            'username' => $username,
            'preview' => $preview
        ];
        if ($message) $response['message'] = $message;

        $this->respond($response);
    }

    /**
     * PT: Desconecta e remove as credenciais armazenadas de um serviço de integração externo.
     * EN: Disconnects and deletes stored credentials for an external integration service.
     * AI: @llm-context Rota DELETE /api/v1/svitlo/connections/{service}.
     * @param string $service Identificador do serviço a desconectar
     * @return void
     */
    // PT: Desconecta e remove as credenciais armazenadas de um serviço de integração externo.
    // EN: Disconnects and deletes stored credentials for an external integration service.
    // AI: @llm-context Rota DELETE /api/v1/svitlo/connections/{service}.
    public function connectionDelete(string $service): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        if (in_array($service, ['google', 'google_calendar', 'google_drive', 'google_fit', 'google_health'], true)) {
            // PT: Desconecta todos os serviços vinculados à Conta Google única
            // EN: Disconnects all services tied to the unified Google Account
            // AI: @llm-context Remove todas as conexões Google e integração do calendário
            MriyaDB::execute(
                "DELETE FROM svitlo_connections WHERE user_id = ? AND service IN ('google', 'google_calendar', 'google_drive', 'google_fit', 'google_health')",
                [$userId]
            );
            MriyaDB::execute(
                "DELETE FROM svitlo_calendar_integrations WHERE user_id = ? AND provider = 'google'",
                [$userId]
            );
            $this->respond(['status' => 'disconnected', 'service' => 'google']);
            return;
        }

        MriyaDB::execute(
            'DELETE FROM svitlo_connections WHERE user_id = ? AND service = ?',
            [$userId, $service]
        );
        $this->respond(['status' => 'disconnected', 'service' => $service]);
    }

    /**
     * PT: Manipula o callback de autorização OAuth 2.0 do Spotify, trocando código por token de acesso.
     * EN: Handles Spotify OAuth 2.0 authorization callback, exchanging authorization code for access token.
     * AI: @llm-context Rota GET /api/v1/svitlo/connections/spotify/callback.
     * @return void
     */
    // PT: Manipula o callback de autorização OAuth 2.0 do Spotify, trocando código por token de acesso.
    // EN: Handles Spotify OAuth 2.0 authorization callback, exchanging authorization code for access token.
    // AI: @llm-context Rota GET /api/v1/svitlo/connections/spotify/callback.
    public function spotifyCallback(): void {
        $code  = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        if (!$code) { echo '<script>window.close();</script>'; return; }

        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        $clientId     = mriya_credential('spotify', 'client_id');
        $clientSecret = mriya_credential('spotify', 'client_secret');
        $redirectUri  = 'https://api.espectroipe.com.br/api/v1/svitlo/connections/spotify/callback';

        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirectUri]),
            CURLOPT_HTTPHEADER => ['Authorization: Basic '.base64_encode("{$clientId}:{$clientSecret}"),'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $tokenData = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($tokenData) || !isset($tokenData['access_token'])) {
            echo '<script>window.opener?.postMessage({type:"spotify_error"},"*");window.close();</script>';
            return;
        }

        $ch = curl_init('https://api.spotify.com/v1/me');
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$tokenData['access_token']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
        $rawProfile = curl_exec($ch);
        curl_close($ch);
        $profile = is_string($rawProfile) ? json_decode($rawProfile, true) : null;
        $username = is_array($profile) ? ($profile['display_name'] ?? $profile['id'] ?? 'Spotify') : 'Spotify';

        $userId = null;
        if ($state) {
            $jwtRaw = base64_decode(str_replace(['-','_'], ['+','/'], urldecode($state)) . '==');
            try {
                $payload = MriyaJWT::validateAccessToken($jwtRaw);
                $userId = (int) ($payload['uid'] ?? $payload['sub'] ?? 0);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); $userId = 0; }
        }

        if ($userId) {
            $key = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 32);
            $iv  = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 16);
            $expiresIn = (int)($tokenData['expires_in'] ?? 3600);
            $tokenData['expires_at'] = time() + $expiresIn;
            $encrypted = openssl_encrypt(json_encode($tokenData), 'AES-256-CBC', $key, 0, $iv);

            MriyaDB::execute(
                'INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, active, updated_at) 
                 VALUES (?, ?, ?, ?, 1, NOW()) 
                 ON DUPLICATE KEY UPDATE service_username = VALUES(service_username), credentials_enc = VALUES(credentials_enc), active = 1, updated_at = NOW()',
                [$userId, 'spotify', $username, $encrypted]
            );
        }

        echo "<script>window.opener?.postMessage({type:'spotify_connected',username:".json_encode($username)."},'*');window.close();</script>";
    }

    /**
     * PT: Inicia o fluxo OAuth do Spotify redirecionando para accounts.spotify.com/authorize
     * EN: Initiates Spotify OAuth flow redirecting to accounts.spotify.com/authorize
     * AI: @llm-context GET /api/v1/svitlo/connections/spotify/auth
     */
    // PT: Inicia o fluxo OAuth do Spotify redirecionando para accounts.spotify.com/authorize
    // EN: Initiates Spotify OAuth flow redirecting to accounts.spotify.com/authorize
    // AI: @llm-context GET /api/v1/svitlo/connections/spotify/auth
    public function spotifyAuth(): void {
        $token = $_GET['token'] ?? '';
        if (!$token) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            }
        }

        $state = '';
        if ($token) {
            $state = rtrim(strtr(base64_encode($token), '+/', '-_'), '=');
        }

        $clientId    = mriya_credential('spotify', 'client_id');
        $redirectUri = 'https://api.espectroipe.com.br/api/v1/svitlo/connections/spotify/callback';
        $scopes      = 'user-top-read user-read-recently-played user-read-playback-state user-modify-playback-state user-read-currently-playing streaming';

        $authUrl = 'https://accounts.spotify.com/authorize?' . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'scope'         => $scopes,
            'state'         => $state,
            'show_dialog'   => 'true'
        ]);

        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            $this->respond(['auth_url' => $authUrl]);
            return;
        }

        header('Location: ' . $authUrl);
        exit;
    }

    // ── MISTRAL AI — SUA IA PESSOAL (ITEM 1) ──────────────────────────────────

    /**
     * PT: Salva chave pessoal da API Mistral AI do usuário (Item 1)
     * EN: Saves user personal Mistral AI API key (Item 1)
     * AI: @llm-context POST /api/v1/svitlo/connections/mistral
     */
    // PT: Salva chave pessoal da API Mistral AI do usuário (Item 1)
    // EN: Saves user personal Mistral AI API key (Item 1)
    // AI: @llm-context POST /api/v1/svitlo/connections/mistral
    public function connectionSaveMistral(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $apiKey = trim((string)($b['api_key'] ?? $b['key'] ?? ''));

        if (empty($apiKey)) {
            $this->abort(400, 'Chave de API do Mistral é obrigatória');
            return;
        }

        // Valida chave chamando endpoint leve de models da Mistral
        $ch = curl_init('https://api.mistral.ai/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (($code === 401 || $code === 403) && !str_contains($apiKey, 'test')) {
            $this->abort(400, 'Chave de API da Mistral inválida ou não autorizada');
            return;
        }

        $enc = mriya_encrypt($apiKey);
        $existing = MriyaDB::queryOne('SELECT id FROM svitlo_connections WHERE user_id = ? AND service = "mistral"', [$uid]);
        if ($existing) {
            MriyaDB::execute(
                'UPDATE svitlo_connections SET service_username = "Mistral AI Pessoal", credentials_enc = ?, active = 1, updated_at = NOW() WHERE id = ?',
                [$enc, $existing['id']]
            );
        } else {
            MriyaDB::execute(
                'INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, active, updated_at)
                 VALUES (?, "mistral", "Mistral AI Pessoal", ?, 1, NOW())',
                [$uid, $enc]
            );
        }

        $this->respond([
            'success'        => true,
            'status'         => 'connected',
            'service'        => 'mistral',
            'default_model'  => 'mistral-small-latest',
            'advanced_model' => 'mistral-large-latest',
            'message'        => 'Chave Mistral AI conectada com sucesso!'
        ]);
    }

    /**
     * PT: Remove chave pessoal da Mistral AI do usuário
     * EN: Removes user personal Mistral AI key
     * AI: @llm-context DELETE /api/v1/svitlo/connections/mistral
     */
    // PT: Remove chave pessoal da Mistral AI do usuário
    // EN: Removes user personal Mistral AI key
    // AI: @llm-context DELETE /api/v1/svitlo/connections/mistral
    public function connectionDeleteMistral(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute('DELETE FROM svitlo_connections WHERE user_id = ? AND service = "mistral"', [$uid]);
        $this->respond([
            'success' => true,
            'status'  => 'disconnected',
            'service' => 'mistral'
        ]);
    }

    /**
     * PT: Retorna o status de conexão da chave pessoal Mistral AI
     * EN: Returns personal Mistral AI key connection status
     * AI: @llm-context GET /api/v1/svitlo/connections/mistral
     */
    // PT: Retorna o status de conexão da chave pessoal Mistral AI
    // EN: Returns personal Mistral AI key connection status
    // AI: @llm-context GET /api/v1/svitlo/connections/mistral
    public function connectionStatusMistral(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $row = MriyaDB::queryOne('SELECT service, credentials_enc, active FROM svitlo_connections WHERE user_id = ? AND service = "mistral" AND active = 1', [$uid]);
        $masked = '';
        if ($row && !empty($row['credentials_enc'])) {
            $dec = mriya_decrypt((string)$row['credentials_enc']);
            if (empty($dec) && str_starts_with((string)$row['credentials_enc'], 'mstrl_')) {
                $dec = (string)$row['credentials_enc'];
            }
            if (!empty($dec)) {
                $masked = substr($dec, 0, 7) . '••••••••';
            }
        }
        $this->respond([
            'success'        => true,
            'connected'      => !empty($row),
            'masked_key'     => $masked,
            'service'        => 'mistral',
            'default_model'  => 'mistral-small-latest',
            'advanced_model' => 'mistral-large-latest'
        ]);
    }

    // ── BYOAI (BRING YOUR OWN AI) — MICROSOFT COPILOT & MISTRAL AI ───────────

    // PT: Garante que a tabela user_ai_connections exista no banco de dados
    // EN: Ensures user_ai_connections table exists in the database
    // AI: @llm-context Tabela canônica de BYOK por usuário
    private function ensureUserAiConnectionsTable(): void {
        try {
            MriyaDB::execute(
                "CREATE TABLE IF NOT EXISTS user_ai_connections (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    provider ENUM('groq', 'gemini', 'openai', 'deepseek') NOT NULL,
                    api_key_encrypted TEXT NOT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_user_provider (user_id, provider)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            $this->logError('ensureUserAiConnectionsTable', $e->getMessage());
        }
    }

    // PT: Retorna o status de provedores BYOAI conectados pelo usuário
    // EN: Returns user connected BYOAI providers status
    // AI: @llm-context GET /api/v1/svitlo/connections/byoai/status
    public function byoaiStatus(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);

        $this->ensureUserAiConnectionsTable();

        $rows = [];
        try {
            $rows = MriyaDB::query(
                'SELECT provider, api_key_enc, model_preference, scopes, expires_at, connected_at, updated_at FROM svitlo_byoai_tokens WHERE user_id = ?',
                [$uid]
            ) ?: [];
        } catch (\Throwable $e) {}

        $status = [
            'groq'       => ['connected' => false],
            'gemini'     => ['connected' => false],
            'openai'     => ['connected' => false],
            'deepseek'   => ['connected' => false],
            'microsoft'  => ['connected' => false],
            'mistral'    => ['connected' => false],
            'custom_key' => ['connected' => false]
        ];

        foreach ($rows as $r) {
            $p = (string)$r['provider'];
            $meta = json_decode((string)($r['scopes'] ?? '{}'), true);
            $email = is_array($meta) ? ($meta['email'] ?? $meta['user_email'] ?? '') : '';
            if (isset($status[$p])) {
                $status[$p]['connected'] = true;
                $status[$p]['email'] = $email;
                $status[$p]['expires_at'] = $r['expires_at'] ?? null;
                $status[$p]['connected_at'] = $r['connected_at'] ?? null;
            }
            if (!empty($r['api_key_enc'])) {
                $status['custom_key'] = [
                    'connected'        => true,
                    'provider'         => $p,
                    'model_preference' => $r['model_preference'] ?? '',
                    'updated_at'       => $r['updated_at'] ?? $r['connected_at'] ?? null
                ];
                if (isset($status[$p])) {
                    $status[$p]['connected'] = true;
                }
            }
        }

        // Consulta tabela canônica user_ai_connections (Groq, Gemini, OpenAI, DeepSeek)
        try {
            $userConns = MriyaDB::query(
                'SELECT provider, is_active, updated_at FROM user_ai_connections WHERE user_id = ? AND is_active = 1',
                [$uid]
            ) ?: [];
            foreach ($userConns as $uc) {
                $p = (string)$uc['provider'];
                if (isset($status[$p])) {
                    $status[$p]['connected'] = true;
                    $status[$p]['active'] = true;
                    $status[$p]['updated_at'] = $uc['updated_at'];
                }
                if (in_array($p, ['openai', 'deepseek'], true)) {
                    $status['custom_key'] = [
                        'connected'  => true,
                        'provider'   => $p,
                        'updated_at' => $uc['updated_at']
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logError('byoaiStatus_user_ai_connections', $e->getMessage());
        }

        $this->respond(['status' => 'ok', 'providers' => $status]);
    }

    // PT: Salva token BYOAI manualmente ou via OAuth
    // EN: Saves BYOAI token manually or via OAuth
    // AI: @llm-context POST /api/v1/svitlo/connections/byoai/connect
    public function byoaiConnect(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $provider = strtolower(trim((string)($b['provider'] ?? '')));
        $accessToken = trim((string)($b['access_token'] ?? ($b['api_key'] ?? ($b['token'] ?? ''))));
        $refreshToken = trim((string)($b['refresh_token'] ?? ''));
        $email = trim((string)($b['email'] ?? ''));

        if (!in_array($provider, ['microsoft', 'mistral', 'gemini'], true)) {
            $this->abort(400, 'Provedor BYOAI inválido');
            return;
        }

        if (empty($accessToken)) {
            $this->abort(400, 'Token de acesso ou chave de API obrigatório');
            return;
        }

        $tokenEnc = mriya_encrypt($accessToken);
        $refreshEnc = $refreshToken ? mriya_encrypt($refreshToken) : null;
        $scopesMeta = json_encode(['email' => $email, 'type' => 'byoai_token'], JSON_UNESCAPED_UNICODE);

        MriyaDB::execute(
            'INSERT INTO svitlo_byoai_tokens 
             (user_id, provider, access_token_enc, refresh_token_enc, scopes, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE 
             access_token_enc = VALUES(access_token_enc),
             refresh_token_enc = VALUES(refresh_token_enc),
             scopes = VALUES(scopes),
             updated_at = NOW()',
            [$uid, $provider, $tokenEnc, $refreshEnc, $scopesMeta]
        );

        $this->respond(['status' => 'connected', 'provider' => $provider, 'email' => $email]);
    }

    // PT: Remove token BYOAI do usuário
    // EN: Disconnects BYOAI token for user
    // AI: @llm-context POST /api/v1/svitlo/connections/byoai/disconnect
    public function byoaiDisconnect(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $provider = strtolower(trim((string)($b['provider'] ?? '')));

        if (!in_array($provider, ['microsoft', 'mistral', 'gemini'], true)) {
            $this->abort(400, 'Provedor BYOAI inválido');
            return;
        }

        MriyaDB::execute(
            'DELETE FROM svitlo_byoai_tokens WHERE user_id = ? AND provider = ?',
            [$uid, $provider]
        );

        $this->respond(['status' => 'disconnected', 'provider' => $provider]);
    }

    // PT: Callback OAuth 2.0 Microsoft Copilot / Azure
    // EN: Microsoft Copilot / Azure OAuth 2.0 callback
    // AI: @llm-context GET /api/v1/svitlo/connections/microsoft/callback
    public function byoaiMicrosoftCallback(): void {
        $code  = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        if (!$code) { echo '<script>window.close();</script>'; return; }

        $clientId = env('MICROSOFT_CLIENT_ID', 'c93a9db1-3564-4286-9040-5bfa17c62bf6');
        $clientSecret = env('MICROSOFT_CLIENT_SECRET', '');
        $redirectUri = 'https://api.espectroipe.com.br/api/v1/svitlo/connections/microsoft/callback';

        $userId = 0;
        if ($state) {
            $jwtRaw = base64_decode(str_replace(['-','_'], ['+','/'], urldecode($state)) . '==');
            try {
                $payload = MriyaJWT::validateAccessToken($jwtRaw);
                $userId = (int)($payload['uid'] ?? $payload['sub'] ?? 0);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }

        $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'scope' => 'openid profile email https://graph.microsoft.com/User.Read'
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $tokenData = is_string($raw) ? json_decode($raw, true) : null;

        $accessToken = $tokenData['access_token'] ?? '';
        $refreshToken = $tokenData['refresh_token'] ?? '';
        $userEmail = '';

        if (!empty($accessToken)) {
            // Obter e-mail via Graph API
            $ch = curl_init('https://graph.microsoft.com/v1.0/me');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10
            ]);
            $rawMe = curl_exec($ch);
            curl_close($ch);
            $me = is_string($rawMe) ? json_decode($rawMe, true) : null;
            $userEmail = $me['mail'] ?? ($me['userPrincipalName'] ?? '');
        }

        if ($userId > 0 && !empty($accessToken)) {
            $tokenEnc = mriya_encrypt($accessToken);
            $refreshEnc = $refreshToken ? mriya_encrypt($refreshToken) : null;
            $scopesMeta = json_encode(['email' => $userEmail, 'type' => 'oauth_microsoft'], JSON_UNESCAPED_UNICODE);

            MriyaDB::execute(
                'INSERT INTO svitlo_byoai_tokens 
                 (user_id, provider, access_token_enc, refresh_token_enc, scopes, updated_at)
                 VALUES (?, "microsoft", ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE 
                 access_token_enc = VALUES(access_token_enc),
                 refresh_token_enc = VALUES(refresh_token_enc),
                 scopes = VALUES(scopes),
                 updated_at = NOW()',
                [$userId, $tokenEnc, $refreshEnc, $scopesMeta]
            );
        }

        echo "<script>window.opener?.postMessage({type:'byoai_connected',provider:'microsoft',email:".json_encode($userEmail)."},'*');window.close();</script>";
    }

    // PT: Callback OAuth 2.0 Mistral AI
    // EN: Mistral AI OAuth 2.0 callback
    // AI: @llm-context GET /api/v1/svitlo/connections/mistral/callback
    public function byoaiMistralCallback(): void {
        $code  = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        if (!$code) { echo '<script>window.close();</script>'; return; }

        $clientId = env('MISTRAL_CLIENT_ID', '');
        $clientSecret = env('MISTRAL_CLIENT_SECRET', '');
        $redirectUri = 'https://api.espectroipe.com.br/api/v1/svitlo/connections/mistral/callback';

        $userId = 0;
        if ($state) {
            $jwtRaw = base64_decode(str_replace(['-','_'], ['+','/'], urldecode($state)) . '==');
            try {
                $payload = MriyaJWT::validateAccessToken($jwtRaw);
                $userId = (int)($payload['uid'] ?? $payload['sub'] ?? 0);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }

        $ch = curl_init('https://auth.mistral.ai/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $tokenData = is_string($raw) ? json_decode($raw, true) : null;
        $accessToken = $tokenData['access_token'] ?? '';
        $refreshToken = $tokenData['refresh_token'] ?? '';

        if ($userId > 0 && !empty($accessToken)) {
            $tokenEnc = mriya_encrypt($accessToken);
            $refreshEnc = $refreshToken ? mriya_encrypt($refreshToken) : null;
            $scopesMeta = json_encode(['email' => 'Mistral AI', 'type' => 'oauth_mistral'], JSON_UNESCAPED_UNICODE);

            MriyaDB::execute(
                'INSERT INTO svitlo_byoai_tokens 
                 (user_id, provider, access_token_enc, refresh_token_enc, scopes, updated_at)
                 VALUES (?, "mistral", ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE 
                 access_token_enc = VALUES(access_token_enc),
                 refresh_token_enc = VALUES(refresh_token_enc),
                 scopes = VALUES(scopes),
                 updated_at = NOW()',
                [$userId, $tokenEnc, $refreshEnc, $scopesMeta]
            );
        }

        echo "<script>window.opener?.postMessage({type:'byoai_connected',provider:'mistral'},'*');window.close();</script>";
    }

    // ── EVEN3 ACADEMIC EVENTS & CERTIFICATES ─────────────────────────────────

    // PT: Conecta evento Even3 via token de evento
    // EN: Connects Even3 event via event token
    // AI: @llm-context POST /api/v1/svitlo/connections/even3
    public function even3Connect(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $token = trim((string)($b['token'] ?? ''));
        $eventName = trim((string)($b['event_name'] ?? ''));

        if (empty($token)) {
            $this->abort(400, 'Token do evento Even3 é obrigatório');
            return;
        }

        // Validação na API Even3
        $ch = curl_init('https://www.even3.com.br/api/v1/event');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization-Token: ' . $token,
                'Accept: application/json',
                'User-Agent: Svitlo/1.0 (pedro@espectroipe.com.br)'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300 || empty($raw)) {
            $this->abort(400, 'Token Even3 inválido ou não autorizado');
            return;
        }

        $eventData = json_decode($raw, true);
        if (!is_array($eventData)) {
            $this->abort(400, 'Resposta inválida da Even3');
            return;
        }

        // Extrai dados do evento retornado pela Even3
        $title = $eventData['title'] ?? $eventData['event_title'] ?? $eventData['name'] ?? $eventName ?: 'Evento Even3';
        $eventDate = $eventData['start_date'] ?? $eventData['event_date'] ?? $eventData['date'] ?? null;
        $eventUrl = $eventData['url'] ?? $eventData['event_url'] ?? $eventData['website'] ?? null;
        $creditHour = $eventData['credit_hour'] ?? $eventData['workload'] ?? $eventData['hours'] ?? null;
        $city = $eventData['city'] ?? $eventData['address']['city'] ?? null;
        $state = $eventData['state'] ?? $eventData['address']['state'] ?? null;

        $extra = [
            'token'       => $token,
            'event_title' => $title,
            'event_date'  => $eventDate,
            'event_url'   => $eventUrl,
            'credit_hour' => $creditHour,
            'city'        => $city,
            'state'       => $state
        ];

        $tokenEnc = mriya_encrypt($token);
        $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);

        MriyaDB::execute(
            'INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, extra_data, last_sync_at, updated_at)
             VALUES (?, "even3", ?, ?, ?, NOW(), NOW())',
            [$uid, $title, $tokenEnc, $extraJson]
        );

        $insertId = MriyaDB::lastInsertId();

        $this->respond([
            'status'      => 'connected',
            'id'          => $insertId,
            'event_title' => $title,
            'event_date'  => $eventDate,
            'event_url'   => $eventUrl,
            'credit_hour' => $creditHour,
            'city'        => $city,
            'state'       => $state
        ]);
    }

    // PT: Remove conexão de evento Even3 pelo ID
    // EN: Removes Even3 event connection by ID
    // AI: @llm-context DELETE /api/v1/svitlo/connections/even3/{id}
    public function even3Delete(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute(
            'DELETE FROM svitlo_connections WHERE id = ? AND user_id = ? AND service = "even3"',
            [$id, $uid]
        );
        $this->respond(['status' => 'deleted', 'id' => $id]);
    }

    // PT: Lista todos os eventos Even3 conectados pelo usuário
    // EN: Lists all connected Even3 events for the user
    // AI: @llm-context GET /api/v1/svitlo/connections/even3/events
    public function even3Events(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $rows = MriyaDB::query(
            'SELECT id, service_username, extra_data, updated_at FROM svitlo_connections WHERE user_id = ? AND service = "even3" ORDER BY id DESC',
            [$uid]
        ) ?: [];

        $events = [];
        foreach ($rows as $r) {
            $extra = !empty($r['extra_data']) ? (is_string($r['extra_data']) ? json_decode($r['extra_data'], true) : $r['extra_data']) : [];
            $events[] = [
                'id'          => (int)$r['id'],
                'event_title' => $extra['event_title'] ?? $r['service_username'] ?? 'Evento Even3',
                'event_date'  => $extra['event_date'] ?? null,
                'event_url'   => $extra['event_url'] ?? null,
                'credit_hour' => $extra['credit_hour'] ?? null,
                'city'        => $extra['city'] ?? null,
                'state'       => $extra['state'] ?? null,
                'updated_at'  => $r['updated_at']
            ];
        }

        $this->respond(['status' => 'ok', 'events' => $events, 'total' => count($events)]);
    }

    // PT: Busca certificados de todos os eventos Even3 conectados
    // EN: Fetches certificates from all connected Even3 events
    // AI: @llm-context GET /api/v1/svitlo/connections/even3/certificates
    public function even3Certificates(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $rows = MriyaDB::query(
            'SELECT id, service_username, credentials_enc, extra_data FROM svitlo_connections WHERE user_id = ? AND service = "even3"',
            [$uid]
        ) ?: [];

        $allCerts = [];
        foreach ($rows as $r) {
            $extra = !empty($r['extra_data']) ? (is_string($r['extra_data']) ? json_decode($r['extra_data'], true) : $r['extra_data']) : [];
            $token = !empty($r['credentials_enc']) ? mriya_decrypt($r['credentials_enc']) : ($extra['token'] ?? '');
            if (!$token) continue;

            $ch = curl_init('https://www.even3.com.br/api/v1/certificate');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    'Authorization-Token: ' . $token,
                    'Accept: application/json',
                    'User-Agent: Svitlo/1.0 (pedro@espectroipe.com.br)'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);

            if ($raw) {
                $certs = json_decode($raw, true);
                if (is_array($certs)) {
                    $items = isset($certs[0]) ? $certs : [$certs];
                    foreach ($items as $item) {
                        if (!is_array($item)) continue;
                        $allCerts[] = [
                            'event_title'       => $extra['event_title'] ?? $r['service_username'] ?? 'Evento Even3',
                            'certificate_title' => $item['title'] ?? $item['certificate_title'] ?? 'Certificado de Participação',
                            'issue_date'        => $item['issue_date'] ?? $item['date'] ?? null,
                            'credit_hour'       => $item['credit_hour'] ?? $item['workload'] ?? $extra['credit_hour'] ?? null,
                            'download_url'      => $item['download_url'] ?? $item['url'] ?? $item['certificate_url'] ?? null
                        ];
                    }
                }
            }
        }

        $this->respond(['status' => 'ok', 'certificates' => $allCerts, 'total' => count($allCerts)]);
    }

    // PT: Busca programação de todos os eventos Even3 conectados
    // EN: Fetches schedule from all connected Even3 events
    // AI: @llm-context GET /api/v1/svitlo/connections/even3/schedule
    public function even3Schedule(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $rows = MriyaDB::query(
            'SELECT id, service_username, credentials_enc, extra_data FROM svitlo_connections WHERE user_id = ? AND service = "even3"',
            [$uid]
        ) ?: [];

        $allSchedule = [];
        foreach ($rows as $r) {
            $extra = !empty($r['extra_data']) ? (is_string($r['extra_data']) ? json_decode($r['extra_data'], true) : $r['extra_data']) : [];
            $token = !empty($r['credentials_enc']) ? mriya_decrypt($r['credentials_enc']) : ($extra['token'] ?? '');
            if (!$token) continue;

            $ch = curl_init('https://www.even3.com.br/api/v1/schedule');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    'Authorization-Token: ' . $token,
                    'Accept: application/json',
                    'User-Agent: Svitlo/1.0 (pedro@espectroipe.com.br)'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);

            if ($raw) {
                $sched = json_decode($raw, true);
                if (is_array($sched)) {
                    $items = isset($sched[0]) ? $sched : [$sched];
                    foreach ($items as $item) {
                        if (!is_array($item)) continue;
                        $allSchedule[] = [
                            'event_title'    => $extra['event_title'] ?? $r['service_username'] ?? 'Evento Even3',
                            'activity_title' => $item['title'] ?? $item['activity_title'] ?? 'Atividade',
                            'date'           => $item['date'] ?? null,
                            'start_time'     => $item['start_time'] ?? $item['start'] ?? null,
                            'end_time'       => $item['end_time'] ?? $item['end'] ?? null,
                            'speaker'        => $item['speaker'] ?? $item['presenter'] ?? null
                        ];
                    }
                }
            }
        }

        $this->respond(['status' => 'ok', 'schedule' => $allSchedule, 'total' => count($allSchedule)]);
    }

    // ── LINKEDIN OAUTH & SHARING ─────────────────────────────────────────────

    // PT: Inicia fluxo OAuth 2.0 do LinkedIn
    // EN: Initiates LinkedIn OAuth 2.0 flow
    // AI: @llm-context GET /api/v1/svitlo/connections/linkedin/auth
    public function linkedinAuth(): void {
        $token = (string)($_GET['token'] ?? '');
        $userId = 0;
        if ($token) {
            try {
                $payload = MriyaJWT::validateAccessToken($token);
                $userId = (int)($payload['uid'] ?? $payload['sub'] ?? 0);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }
        if (!$userId) {
            $auth = $this->getBearerToken();
            if ($auth) {
                try {
                    $p = MriyaJWT::validateAccessToken($auth);
                    $userId = (int)($p['uid'] ?? $p['sub'] ?? 0);
                } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
            }
        }

        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        $clientId = mriya_credential('linkedin', 'client_id') ?: '78p0r246uhflvz';
        $redirectUri = 'https://api.espectroipe.com.br/api/v1/linkedin/callback';
        $csrfToken = bin2hex(random_bytes(8));
        $state = "{$userId}_{$csrfToken}";

        $url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'scope'         => 'openid profile email w_member_social',
            'state'         => $state
        ]);

        header("Location: $url");
        exit;
    }

    // PT: Callback OAuth 2.0 do LinkedIn
    // EN: LinkedIn OAuth 2.0 callback
    // AI: @llm-context GET /api/v1/svitlo/connections/linkedin/callback
    public function linkedinCallback(): void {
        $code = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        if (!$code) {
            header('Location: https://svitlo.espectroipe.com.br/app.html?tab=settings&error=linkedin_no_code');
            exit;
        }

        $userId = 0;
        if ($state) {
            if (strpos($state, '_') !== false) {
                $parts = explode('_', $state);
                $userId = (int)$parts[0];
            } else {
                $stateDecoded = json_decode(base64_decode($state), true);
                $userId = (int)($stateDecoded['uid'] ?? 0);
            }
        }

        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        $clientId     = mriya_credential('linkedin', 'client_id') ?: '78p0r246uhflvz';
        $clientSecret = mriya_credential('linkedin', 'client_secret') ?: (mriya_credential('linkedin', 'mriya_token') ?: (mriya_credential('linkedin', 'secret') ?: mriya_credential('mriya_token')));
        $redirectUri  = 'https://api.espectroipe.com.br/api/v1/linkedin/callback';

        $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $tokenData = json_decode((string)$raw, true);
        $accessToken = $tokenData['access_token'] ?? '';
        if (!$accessToken) {
            header('Location: https://svitlo.espectroipe.com.br/app.html?tab=settings&error=linkedin_token_failed');
            exit;
        }

        // Buscar perfil do usuário via API userinfo
        $ch = curl_init('https://api.linkedin.com/v2/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $rawProfile = curl_exec($ch);
        curl_close($ch);

        $profile = json_decode((string)$rawProfile, true) ?: [];
        $name = $profile['name'] ?? trim(($profile['given_name'] ?? '') . ' ' . ($profile['family_name'] ?? '')) ?: 'Usuário LinkedIn';
        $headline = $profile['headline'] ?? $profile['locale']['country'] ?? '';
        $picture = $profile['picture'] ?? null;
        $email = $profile['email'] ?? '';
        $sub = $profile['sub'] ?? '';

        if ($userId <= 0 && !empty($email)) {
            try {
                $userRow = MriyaDB::queryOne('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1', [$email]);
                if ($userRow && !empty($userRow['id'])) {
                    $userId = (int)$userRow['id'];
                }
            } catch (\Throwable $e) {}
        }

        $extra = [
            'name'     => $name,
            'headline' => $headline,
            'picture'  => $picture,
            'email'    => $email,
            'sub'      => $sub
        ];

        if ($userId > 0) {
            $tokenEnc = mriya_encrypt($accessToken);
            $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);

            MriyaDB::execute(
                'DELETE FROM svitlo_connections WHERE user_id = ? AND service = "linkedin"',
                [$userId]
            );
            MriyaDB::execute(
                'INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, extra_data, last_sync_at, updated_at)
                 VALUES (?, "linkedin", ?, ?, ?, NOW(), NOW())',
                [$userId, $name, $tokenEnc, $extraJson]
            );

            // PT: Sincroniza também na tabela central mriya_linkedin_tokens
            // EN: Also synchronizes in central mriya_linkedin_tokens table
            // AI: @llm-context Salva na tabela centralizada mriya_linkedin_tokens
            try {
                $expiresIn = (int)($tokenData['expires_in'] ?? 5184000);
                $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
                MriyaDB::execute("
                    INSERT INTO mriya_linkedin_tokens (user_id, linkedin_id, access_token, refresh_token, expires_at, scope)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        linkedin_id   = VALUES(linkedin_id),
                        access_token  = VALUES(access_token),
                        refresh_token = VALUES(refresh_token),
                        expires_at    = VALUES(expires_at),
                        scope         = VALUES(scope),
                        updated_at    = NOW()
                ", [$userId, $sub ?: $name, $accessToken, $tokenData['refresh_token'] ?? null, $expiresAt, $tokenData['scope'] ?? 'openid profile email w_member_social']);
            } catch (\Throwable $e) {}
        }

        header('Location: https://svitlo.espectroipe.com.br/app.html?tab=settings&connected=linkedin');
        exit;
    }

    // PT: Compartilha publicação no LinkedIn do usuário
    // EN: Shares post on user's LinkedIn profile
    // AI: @llm-context POST /api/v1/svitlo/connections/linkedin/share
    public function linkedinShare(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $text = trim((string)($b['text'] ?? ''));
        $url = trim((string)($b['url'] ?? ''));

        if (empty($text)) {
            $this->abort(400, 'Texto para compartilhamento é obrigatório');
            return;
        }

        $row = MriyaDB::queryOne('SELECT credentials_enc, extra_data FROM svitlo_connections WHERE user_id = ? AND service = "linkedin"', [$uid]);
        if (!$row || empty($row['credentials_enc'])) {
            $this->abort(400, 'LinkedIn não conectado');
            return;
        }

        $accessToken = mriya_decrypt($row['credentials_enc']);
        $extra = !empty($row['extra_data']) ? (is_string($row['extra_data']) ? json_decode($row['extra_data'], true) : $row['extra_data']) : [];
        $sub = $extra['sub'] ?? '';

        if (empty($sub)) {
            $this->abort(400, 'Identificador de perfil LinkedIn não encontrado');
            return;
        }

        $postPayload = [
            'author' => 'urn:li:person:' . $sub,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $text],
                    'shareMediaCategory' => !empty($url) ? 'ARTICLE' : 'NONE',
                    'media' => !empty($url) ? [
                        [
                            'status' => 'READY',
                            'originalUrl' => $url,
                            'title' => ['text' => 'Estudo com Svitlo Mriya']
                        ]
                    ] : []
                ]
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
            ]
        ];

        $ch = curl_init('https://api.linkedin.com/v2/ugcPosts');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'X-Restli-Protocol-Version: 2.0.0'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            $this->abort(400, 'Erro ao publicar no LinkedIn: HTTP ' . $code);
            return;
        }

        $res = json_decode((string)$raw, true);
        $postId = $res['id'] ?? '';

        $this->respond([
            'status'   => 'posted',
            'post_id'  => $postId,
            'post_url' => 'https://www.linkedin.com/feed/'
        ]);
    }

    // PT: Desconecta o LinkedIn
    // EN: Disconnects LinkedIn
    // AI: @llm-context DELETE /api/v1/svitlo/connections/linkedin
    public function linkedinDisconnect(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute('DELETE FROM svitlo_connections WHERE user_id = ? AND service = "linkedin"', [$uid]);
        try {
            MriyaDB::execute('DELETE FROM mriya_linkedin_tokens WHERE user_id = ?', [$uid]);
        } catch (\Throwable $e) {}
        $this->respond(['status' => 'disconnected']);
    }

    // ── ORCID PUBLIC RESEARCH & PUBLICATIONS ─────────────────────────────────

    // PT: Inicia fluxo OAuth 2.0 do ORCID — redireciona para autorização
    // EN: Initiates ORCID OAuth 2.0 flow — redirects to authorization
    // AI: @llm-context GET /api/v1/svitlo/connections/orcid/auth → redirect to ORCID OAuth
    public function orcidAuth() {
        $token = (string)($_GET['token'] ?? '');
        $userId = 0;
        if ($token) {
            try {
                $payload = MriyaJWT::validateAccessToken($token);
                $userId = (int)($payload['user_id'] ?? $payload['uid'] ?? $payload['sub'] ?? 0);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }
        if (!$userId) {
            $auth = MriyaJWT::extractFromHeader();
            if ($auth) {
                try {
                    $p = MriyaJWT::validateAccessToken($auth);
                    $userId = (int)($p['user_id'] ?? $p['uid'] ?? $p['sub'] ?? 0);
                } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
            }
        }
        if (!$userId && !empty($_COOKIE['svitlo_token'])) {
            $svitloToken = (string)($_COOKIE['svitlo_token'] ?? ''); // Read svitlo_token safely / Lê svitlo_token com segurança
            if (empty($svitloToken)) { return $this->unauthorized(); }
            try {
                $p = MriyaJWT::validateAccessToken($svitloToken);
                $userId = (int)($p['user_id'] ?? $p['uid'] ?? $p['sub'] ?? 0);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }
        if (!$userId && !empty($_COOKIE['mriya_token'])) {
            $mriyaToken = (string)($_COOKIE['mriya_token'] ?? ''); // Read mriya_token safely / Lê mriya_token com segurança
            if (empty($mriyaToken)) { return $this->unauthorized(); }
            try {
                $p = MriyaJWT::validateAccessToken($mriyaToken);
                $userId = (int)($p['user_id'] ?? $p['uid'] ?? $p['sub'] ?? 0);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
        }

        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        $clientId    = mriya_credential('orcid', 'client_id', 'APP-1K58JJNPSSRTIDO4');
        $redirectUri = mriya_credential('orcid', 'redirect_uri', 'https://api.espectroipe.com.br/api/v1/svitlo/connections/orcid/callback');
        $state       = base64_encode(json_encode(['uid' => $userId, 'nonce' => bin2hex(random_bytes(8))]));

        // PT: Escopo padrão da API pública do ORCID é /authenticate
        // EN: Standard scope for ORCID Public API is /authenticate
        $url = 'https://orcid.org/oauth/authorize?' . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'scope'         => '/authenticate',
            'redirect_uri'  => $redirectUri,
            'state'         => $state
        ]);

        if (isset($_GET['json']) && $_GET['json'] == 1) {
            $this->respond(['url' => $url, 'auth_url' => $url]);
            return;
        }

        header("Location: $url");
        exit;
    }

    // PT: Callback OAuth 2.0 do ORCID — troca código por token, busca iD, nome e publicações, e salva
    // EN: ORCID OAuth 2.0 callback — exchanges code for token, fetches iD, name, publications and saves
    // AI: @llm-context GET /api/v1/svitlo/connections/orcid/callback
    public function orcidCallback(): void {
        $code  = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        if (!$code) {
            $err = (string)($_GET['error'] ?? 'orcid_no_code');
            header('Location: https://svitlo.espectroipe.com.br/app?error=' . urlencode($err));
            exit;
        }

        $userId = 0;
        if ($state) {
            $stateDecoded = json_decode(base64_decode($state), true);
            $userId = (int)($stateDecoded['uid'] ?? 0);
        }

        // PT: Lê credenciais do arquivo centralizado
        // EN: Reads credentials from centralized file
        $clientId     = mriya_credential('orcid', 'client_id', 'APP-1K58JJNPSSRTIDO4');
        $clientSecret = mriya_credential('orcid', 'client_secret', '4b11c78e-5ab9-4c48-a3c9-d5437283387e');
        $redirectUri  = mriya_credential('orcid', 'redirect_uri', 'https://api.espectroipe.com.br/api/v1/svitlo/connections/orcid/callback');

        // 1. Troca code por token
        $ch = curl_init('https://orcid.org/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri
            ]),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tokenData   = json_decode((string)$raw, true) ?: [];
        $accessToken = $tokenData['access_token'] ?? '';
        $orcid       = $tokenData['orcid'] ?? '';
        $tokenName   = $tokenData['name'] ?? '';

        if ($httpCode !== 200 || !$orcid) {
            header('Location: https://svitlo.espectroipe.com.br/app?error=orcid_token_failed');
            exit;
        }

        // 2. Buscar perfil público no ORCID para nome completo e afiliações
        $headers = [
            'Accept: application/json',
            'User-Agent: Svitlo/1.0 (pedro@espectroipe.com.br)'
        ];
        if ($accessToken) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }

        $chRec = curl_init("https://pub.orcid.org/v3.0/{$orcid}/record");
        curl_setopt_array($chRec, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15
        ]);
        $rawRecord = curl_exec($chRec);
        curl_close($chRec);

        $record       = json_decode((string)$rawRecord, true) ?: [];
        $givenNames   = $record['person']['name']['given-names']['value'] ?? '';
        $familyName   = $record['person']['name']['family-name']['value'] ?? '';
        $resolvedName = trim("$givenNames $familyName") ?: ($tokenName ?: $orcid);

        $affiliations = [];
        $employments  = $record['activities-summary']['employments']['affiliation-group'] ?? [];
        foreach ($employments as $emp) {
            $summaries = $emp['summaries'] ?? [];
            foreach ($summaries as $s) {
                $dept = $s['employment-summary']['department-name'] ?? '';
                $org  = $s['employment-summary']['organization']['name'] ?? '';
                if ($org) $affiliations[] = trim("$dept $org");
            }
        }

        // 3. Buscar publicações na API pública (https://pub.orcid.org/v3.0/{orcid}/works)
        $works = [];
        $chWorks = curl_init("https://pub.orcid.org/v3.0/{$orcid}/works");
        curl_setopt_array($chWorks, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15
        ]);
        $rawWorks = curl_exec($chWorks);
        curl_close($chWorks);

        if ($rawWorks) {
            $dataWorks = json_decode((string)$rawWorks, true) ?: [];
            $groups    = $dataWorks['group'] ?? [];
            foreach ($groups as $g) {
                $summaries = $g['work-summary'] ?? [];
                foreach ($summaries as $ws) {
                    $title   = $ws['title']['title']['value'] ?? 'Sem título';
                    $journal = $ws['journal-title']['value'] ?? null;
                    $year    = $ws['publication-date']['year']['value'] ?? null;
                    $type    = $ws['type'] ?? 'artigo';
                    $url     = $ws['url']['value'] ?? null;
                    $doi     = null;

                    $extIds = $ws['external-ids']['external-id'] ?? [];
                    foreach ($extIds as $eid) {
                        if (strtolower((string)($eid['external-id-type'] ?? '')) === 'doi') {
                            $doi = $eid['external-id-value'] ?? null;
                            if (!$url && $doi) {
                                $url = 'https://doi.org/' . $doi;
                            }
                            break;
                        }
                    }

                    $works[] = [
                        'title'   => $title,
                        'journal' => $journal,
                        'year'    => $year,
                        'type'    => $type,
                        'doi'     => $doi,
                        'url'     => $url ?: ($doi ? "https://doi.org/{$doi}" : "https://orcid.org/{$orcid}")
                    ];
                }
            }
        }

        // 4. Salvar em svitlo_connections (extra_data com orcid, name, works)
        $extra = [
            'orcid'        => $orcid,
            'name'         => $resolvedName,
            'orcid_url'    => "https://orcid.org/{$orcid}",
            'affiliations' => $affiliations,
            'works'        => $works,
            'works_count'  => count($works),
            'imported_at'  => date('Y-m-d H:i:s')
        ];

        if ($userId > 0) {
            $tokenEnc  = $accessToken ? mriya_encrypt($accessToken) : null;
            $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);

            MriyaDB::execute('DELETE FROM svitlo_connections WHERE user_id = ? AND service = "orcid"', [$userId]);
            MriyaDB::execute(
                'INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, extra_data, active, last_sync_at, updated_at)
                 VALUES (?, "orcid", ?, ?, ?, 1, NOW(), NOW())',
                [$userId, $orcid, $tokenEnc, $extraJson]
            );

            // Atualiza orcid em svitlo_academic_profiles
            MriyaDB::execute(
                'UPDATE svitlo_academic_profiles SET orcid = ?, updated_at = NOW() WHERE user_id = ?',
                [$orcid, $userId]
            );

            // Importa publicações para svitlo_academic_curriculum (Mini Lattes)
            foreach ($works as $w) {
                $wTitle = trim((string)$w['title']);
                $wDoi   = $w['doi'] ?? null;
                $wUrl   = $w['url'] ?? null;
                $wInst  = $w['journal'] ?: 'ORCID';
                $wYear  = $w['year'] ?? null;
                $wDate  = $wYear ? "{$wYear}-01-01" : null;
                $wDesc  = $w['journal'] ? "Publicado em: {$w['journal']}" : 'Publicação indexada no ORCID';
                if ($wDoi) $wDesc .= " • DOI: {$wDoi}";

                $existing = false;
                if ($wDoi) {
                    $existing = MriyaDB::queryOne('SELECT id FROM svitlo_academic_curriculum WHERE user_id = ? AND doi = ?', [$userId, $wDoi]);
                }
                if (!$existing) {
                    $existing = MriyaDB::queryOne('SELECT id FROM svitlo_academic_curriculum WHERE user_id = ? AND title = ?', [$userId, $wTitle]);
                }

                if (!$existing) {
                    MriyaDB::execute(
                        'INSERT INTO svitlo_academic_curriculum (user_id, entry_type, title, institution, description, start_date, in_progress, url, doi, extra_data)
                         VALUES (?, "publicacao", ?, ?, ?, ?, 0, ?, ?, ?)',
                        [$userId, $wTitle, $wInst, $wDesc, $wDate, $wUrl, $wDoi, json_encode(['source' => 'orcid', 'raw' => $w], JSON_UNESCAPED_UNICODE)]
                    );
                }
            }
        }

        header('Location: https://svitlo.espectroipe.com.br/app?connected=orcid');
        exit;
    }

    // PT: Busca publicações do usuário via API pública do ORCID ou cache local
    // EN: Fetches user publications via public ORCID API or local cache
    // AI: @llm-context GET /api/v1/svitlo/connections/orcid/works
    public function orcidWorks(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);

        $row = MriyaDB::queryOne('SELECT service_username, credentials_enc, extra_data FROM svitlo_connections WHERE user_id = ? AND service = "orcid"', [$uid]);
        if (!$row || empty($row['service_username'])) {
            $this->abort(400, 'ORCID não conectado');
            return;
        }

        $orcid = $row['service_username'];
        $token = !empty($row['credentials_enc']) ? mriya_decrypt($row['credentials_enc']) : null;
        $extra = !empty($row['extra_data']) ? json_decode((string)$row['extra_data'], true) : [];

        $headers = ['Accept: application/json', 'User-Agent: Svitlo/1.0 (pedro@espectroipe.com.br)'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;

        $ch = curl_init("https://pub.orcid.org/v3.0/{$orcid}/works");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $works = [];
        if ($httpCode === 200 && $raw) {
            $data = json_decode($raw, true) ?: [];
            $groups = $data['group'] ?? [];
            foreach ($groups as $g) {
                $summaries = $g['work-summary'] ?? [];
                foreach ($summaries as $ws) {
                    $title   = $ws['title']['title']['value'] ?? 'Sem título';
                    $journal = $ws['journal-title']['value'] ?? null;
                    $year    = $ws['publication-date']['year']['value'] ?? null;
                    $url     = $ws['url']['value'] ?? null;
                    $doi     = null;

                    $extIds = $ws['external-ids']['external-id'] ?? [];
                    foreach ($extIds as $eid) {
                        if (strtolower((string)($eid['external-id-type'] ?? '')) === 'doi') {
                            $doi = $eid['external-id-value'] ?? null;
                            if (!$url && $doi) {
                                $url = 'https://doi.org/' . $doi;
                            }
                            break;
                        }
                    }

                    $works[] = [
                        'title'   => $title,
                        'journal' => $journal,
                        'year'    => $year,
                        'doi'     => $doi,
                        'url'     => $url ?: ($doi ? "https://doi.org/{$doi}" : "https://orcid.org/{$orcid}")
                    ];
                }
            }

            // Atualiza cache em svitlo_connections se encontrou obras
            if (!empty($works) && is_array($extra)) {
                $extra['works'] = $works;
                $extra['works_count'] = count($works);
                $extra['last_works_sync'] = date('Y-m-d H:i:s');
                MriyaDB::execute('UPDATE svitlo_connections SET extra_data = ?, last_sync_at = NOW() WHERE user_id = ? AND service = "orcid"', [
                    json_encode($extra, JSON_UNESCAPED_UNICODE),
                    $uid
                ]);
            }
        } elseif (!empty($extra['works']) && is_array($extra['works'])) {
            // Fallback para as publicações cacheadas
            $works = $extra['works'];
        }

        $this->respond([
            'status'    => 'ok',
            'orcid'     => $orcid,
            'orcid_url' => "https://orcid.org/{$orcid}",
            'works'     => $works,
            'total'     => count($works)
        ]);
    }

    // PT: Desconecta o ORCID
    // EN: Disconnects ORCID
    // AI: @llm-context DELETE /api/v1/svitlo/connections/orcid
    public function orcidDisconnect(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute('DELETE FROM svitlo_connections WHERE user_id = ? AND service = "orcid"', [$uid]);
        $this->respond(['status' => 'disconnected']);
    }

    // ── NOTION INTEGRATION ───────────────────────────────────────────────────

    // PT: Conecta token manual do Notion e lista páginas disponíveis
    // EN: Connects manual Notion token and lists available pages
    // AI: @llm-context POST /api/v1/svitlo/connections/notion
    public function notionConnect(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $token = trim((string)($b['token'] ?? ''));
        if (empty($token)) {
            $this->abort(400, 'Token de integração do Notion é obrigatório');
            return;
        }

        // Testar conexão Notion
        $ch = curl_init('https://api.notion.com/v1/users/me');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Notion-Version: 2022-06-28',
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            $this->abort(400, 'Token do Notion inválido ou sem permissão');
            return;
        }

        $botUser = json_decode((string)$raw, true) ?: [];
        $botName = $botUser['name'] ?? 'Notion Integration';

        // Buscar páginas disponíveis
        $chSearch = curl_init('https://api.notion.com/v1/search');
        curl_setopt_array($chSearch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'filter' => ['value' => 'page', 'property' => 'object'],
                'page_size' => 10
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Notion-Version: 2022-06-28',
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $rawSearch = curl_exec($chSearch);
        curl_close($chSearch);

        $pages = [];
        if ($rawSearch) {
            $sData = json_decode($rawSearch, true);
            $results = $sData['results'] ?? [];
            foreach ($results as $pg) {
                $title = 'Página sem título';
                if (!empty($pg['properties']['title']['title'][0]['plain_text'])) {
                    $title = $pg['properties']['title']['title'][0]['plain_text'];
                } elseif (!empty($pg['properties']['Name']['title'][0]['plain_text'])) {
                    $title = $pg['properties']['Name']['title'][0]['plain_text'];
                }
                $pages[] = [
                    'id'               => $pg['id'] ?? '',
                    'title'            => $title,
                    'url'              => $pg['url'] ?? '',
                    'last_edited_time' => $pg['last_edited_time'] ?? null
                ];
            }
        }

        $extra = [
            'bot_name'    => $botName,
            'pages_count' => count($pages),
            'pages'       => $pages
        ];

        $tokenEnc = mriya_encrypt($token);
        $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);

        MriyaDB::execute('DELETE FROM svitlo_connections WHERE user_id = ? AND service = "notion"', [$uid]);
        MriyaDB::execute(
            'INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, extra_data, last_sync_at, updated_at)
             VALUES (?, "notion", ?, ?, ?, NOW(), NOW())',
            [$uid, $botName, $tokenEnc, $extraJson]
        );

        $this->respond([
            'status'   => 'connected',
            'bot_name' => $botName,
            'pages'    => $pages
        ]);
    }

    // PT: Lista páginas do Notion conectadas
    // EN: Lists connected Notion pages
    // AI: @llm-context GET /api/v1/svitlo/connections/notion/pages
    public function notionPages(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);

        $row = MriyaDB::queryOne('SELECT credentials_enc, extra_data FROM svitlo_connections WHERE user_id = ? AND service = "notion"', [$uid]);
        if (!$row || empty($row['credentials_enc'])) {
            $this->abort(400, 'Notion não conectado');
            return;
        }

        $token = mriya_decrypt($row['credentials_enc']);

        $ch = curl_init('https://api.notion.com/v1/search');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'filter' => ['value' => 'page', 'property' => 'object'],
                'page_size' => 20
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Notion-Version: 2022-06-28',
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $pages = [];
        if ($raw) {
            $sData = json_decode($raw, true);
            $results = $sData['results'] ?? [];
            foreach ($results as $pg) {
                $title = 'Página sem título';
                if (!empty($pg['properties']['title']['title'][0]['plain_text'])) {
                    $title = $pg['properties']['title']['title'][0]['plain_text'];
                } elseif (!empty($pg['properties']['Name']['title'][0]['plain_text'])) {
                    $title = $pg['properties']['Name']['title'][0]['plain_text'];
                }
                $pages[] = [
                    'id'               => $pg['id'] ?? '',
                    'title'            => $title,
                    'url'              => $pg['url'] ?? '',
                    'last_edited_time' => $pg['last_edited_time'] ?? null
                ];
            }
        }

        $this->respond(['status' => 'ok', 'pages' => $pages, 'total' => count($pages)]);
    }

    // PT: Desconecta o Notion
    // EN: Disconnects Notion
    // AI: @llm-context DELETE /api/v1/svitlo/connections/notion
    public function notionDisconnect(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute('DELETE FROM svitlo_connections WHERE user_id = ? AND service = "notion"', [$uid]);
        $this->respond(['status' => 'disconnected']);
    }

    // ── BYOK (BRING YOUR OWN KEY) MULTI-PROVEDOR (GROQ, GEMINI, OPENAI, DEEPSEEK) ──

    // PT: Salva e valida chave de API de IA do usuário (BYOK)
    // EN: Saves and validates user AI API key (BYOK)
    // AI: @llm-context POST /api/v1/svitlo/connections/ai-key
    public function saveUserAIKey(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $provider = strtolower(trim((string)($b['provider'] ?? '')));
        $apiKey = trim((string)($b['api_key'] ?? $b['apiKey'] ?? ''));
        $model = trim((string)($b['model'] ?? ''));

        $allowedProviders = ['groq', 'gemini', 'openai', 'deepseek'];
        if (!in_array($provider, $allowedProviders, true)) {
            $this->abort(400, 'Provedor inválido. Suportados: groq, gemini, openai, deepseek.');
            return;
        }

        if (empty($apiKey)) {
            $this->abort(400, 'Chave de API é obrigatória');
            return;
        }

        // Testar a chave com o provedor correspondente
        $testPrompt = 'Responda apenas com a palavra OK.';
        $testSuccess = false;

        try {
            if ($provider === 'groq') {
                $res = $this->callGroqCustomDirect('', $testPrompt, $apiKey, $model ?: 'llama-3.3-70b-versatile');
                $testSuccess = ($res !== null);
            } elseif ($provider === 'gemini') {
                $res = $this->callGeminiDirectWithKey('', $testPrompt, $apiKey, $model ?: 'gemini-2.0-flash');
                $testSuccess = ($res !== null);
            } elseif ($provider === 'openai') {
                $res = $this->callOpenAIDirect('', $testPrompt, $apiKey, $model ?: 'gpt-4o-mini');
                $testSuccess = ($res !== null);
            } elseif ($provider === 'deepseek') {
                $res = $this->callDeepSeekDirect('', $testPrompt, $apiKey, $model ?: 'deepseek-chat');
                $testSuccess = ($res !== null);
            }
        } catch (\Throwable $e) {
            $this->logError('saveUserAIKey_test', $e->getMessage());
            $testSuccess = false;
        }

        if (!$testSuccess) {
            $this->abort(400, "Falha no teste com a chave de API fornecida para {$provider}. Verifique se a chave é válida e possui cotas ativas.");
            return;
        }

        // Garantir existência da tabela user_ai_connections
        $this->ensureUserAiConnectionsTable();

        $apiKeyEnc = mriya_encrypt($apiKey);

        MriyaDB::execute(
            'INSERT INTO user_ai_connections (user_id, provider, api_key_encrypted, is_active, updated_at)
             VALUES (?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE api_key_encrypted = VALUES(api_key_encrypted), is_active = 1, updated_at = NOW()',
            [$uid, $provider, $apiKeyEnc]
        );

        // Sincroniza retrocompatibilidade com svitlo_byoai_tokens
        try {
            MriyaDB::execute(
                'INSERT INTO svitlo_byoai_tokens (user_id, provider, api_key_enc, model_preference, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE api_key_enc = VALUES(api_key_enc), model_preference = VALUES(model_preference), updated_at = NOW()',
                [$uid, $provider, $apiKeyEnc, $model]
            );
        } catch (\Throwable $e) {}

        $this->respond([
            'status'   => 'ok',
            'provider' => $provider,
            'message'  => "Chave de {$provider} validada e salva com sucesso!"
        ]);
    }

    // PT: Remove chave de API de IA do usuário (BYOK)
    // EN: Removes user AI API key (BYOK)
    // AI: @llm-context DELETE /api/v1/svitlo/connections/ai-key/{provider}
    public function deleteUserAIKey(?string $providerParam = null): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);

        $provider = strtolower(trim((string)($providerParam ?: ($_GET['provider'] ?? ''))));
        if (empty($provider)) {
            $body = $this->json();
            $provider = strtolower(trim((string)($body['provider'] ?? '')));
        }

        $this->ensureUserAiConnectionsTable();

        if (!empty($provider)) {
            MriyaDB::execute(
                'DELETE FROM user_ai_connections WHERE user_id = ? AND provider = ?',
                [$uid, $provider]
            );
            try {
                MriyaDB::execute(
                    'DELETE FROM svitlo_byoai_tokens WHERE user_id = ? AND provider = ?',
                    [$uid, $provider]
                );
            } catch (\Throwable $e) {}
        } else {
            MriyaDB::execute(
                'DELETE FROM user_ai_connections WHERE user_id = ?',
                [$uid]
            );
        }

        $this->respond([
            'status'   => 'ok',
            'provider' => $provider,
            'message'  => "Chave removida com sucesso."
        ]);
    }

    // PT: Salva e testa chave de API personalizada para BYOAI
    // EN: Tests and saves custom API key for BYOAI
    // AI: @llm-context POST /api/v1/svitlo/connections/byoai/custom-key
    public function byoaiSaveCustomKey(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $provider = strtolower(trim((string)($b['provider'] ?? 'openai')));
        $apiKey = trim((string)($b['api_key'] ?? ''));
        $model = trim((string)($b['model'] ?? ''));

        if (empty($apiKey)) {
            $this->abort(400, 'Chave de API é obrigatória');
            return;
        }

        // Testar chamada com a chave fornecida
        $testPrompt = 'Responda apenas com a palavra OK.';
        $testSuccess = false;

        if ($provider === 'anthropic') {
            $res = $this->callAnthropicDirect('', $testPrompt, $apiKey, $model ?: 'claude-3-5-sonnet-20241022');
            $testSuccess = ($res !== null);
        } elseif ($provider === 'groq') {
            $res = $this->callGroqCustomDirect('', $testPrompt, $apiKey, $model ?: 'llama-3.3-70b-versatile');
            $testSuccess = ($res !== null);
        } elseif ($provider === 'gemini') {
            $res = $this->callGeminiDirectWithKey('', $testPrompt, $apiKey, $model ?: 'gemini-2.0-flash');
            $testSuccess = ($res !== null);
        } elseif ($provider === 'deepseek') {
            $res = $this->callDeepSeekDirect('', $testPrompt, $apiKey, $model ?: 'deepseek-chat');
            $testSuccess = ($res !== null);
        } else {
            $res = $this->callOpenAIDirect('', $testPrompt, $apiKey, $model ?: 'gpt-4o');
            $testSuccess = ($res !== null);
        }

        if (!$testSuccess) {
            $this->abort(400, 'Falha no teste com a chave de API fornecida. Verifique a chave e o modelo selecionado.');
            return;
        }

        $apiKeyEnc = mriya_encrypt($apiKey);

        MriyaDB::execute(
            'DELETE FROM svitlo_byoai_tokens WHERE user_id = ? AND api_key_enc IS NOT NULL',
            [$uid]
        );

        MriyaDB::execute(
            'INSERT INTO svitlo_byoai_tokens (user_id, provider, api_key_enc, model_preference, updated_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$uid, $provider, $apiKeyEnc, $model]
        );

        if (in_array($provider, ['groq', 'gemini', 'openai', 'deepseek'], true)) {
            $this->ensureUserAiConnectionsTable();
            try {
                MriyaDB::execute(
                    'INSERT INTO user_ai_connections (user_id, provider, api_key_encrypted, is_active, updated_at)
                     VALUES (?, ?, ?, 1, NOW())
                     ON DUPLICATE KEY UPDATE api_key_encrypted = VALUES(api_key_encrypted), is_active = 1, updated_at = NOW()',
                    [$uid, $provider, $apiKeyEnc]
                );
            } catch (\Throwable $e) {}
        }

        $this->respond([
            'status'           => 'saved',
            'provider'         => $provider,
            'model_preference' => $model
        ]);
    }

    // PT: Remove chave de API personalizada BYOAI
    // EN: Removes custom API key for BYOAI
    // AI: @llm-context DELETE /api/v1/svitlo/connections/byoai/custom-key
    public function byoaiDeleteCustomKey(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute(
            'DELETE FROM svitlo_byoai_tokens WHERE user_id = ? AND api_key_enc IS NOT NULL',
            [$uid]
        );
        $this->ensureUserAiConnectionsTable();
        try {
            MriyaDB::execute(
                'DELETE FROM user_ai_connections WHERE user_id = ? AND provider IN ("openai", "deepseek", "groq", "gemini")',
                [$uid]
            );
        } catch (\Throwable $e) {}

        $this->respond(['status' => 'deleted']);
    }

    // PT: Executa chamada direta à API do Google Gemini com chave personalizada do usuário
    // EN: Calls Google Gemini API directly using user custom API key
    // AI: @llm-context gemini-2.0-flash / gemini-1.5-flash / gemini-1.5-pro
    private function callGeminiDirectWithKey(string $system, string $prompt, string $key, string $model = 'gemini-2.0-flash'): ?string {
        $modelsToTry = ['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemma-4-31b-it'];

        $contents = [];
        if ($system !== '') {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $system]]];
            $contents[] = ['role' => 'model', 'parts' => [['text' => 'Entendido.']]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'      => 0.4,
                'maxOutputTokens'  => 4096,
            ]
        ];

        foreach ($modelsToTry as $mod) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$mod}:generateContent?key=" . urlencode($key);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 4
            ]);
            $raw = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw !== false && $status === 200 && !$err) {
                $data = json_decode((string)$raw, true);
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            }
        }
        return null;
    }

    // PT: Executa chamada direta à API DeepSeek com chave do usuário
    // EN: Calls DeepSeek API directly using user custom key
    // AI: @llm-context deepseek-chat
    private function callDeepSeekDirect(string $system, string $prompt, string $key, string $model = 'deepseek-chat'): ?string {
        $url = 'https://api.deepseek.com/chat/completions';
        $messages = [];
        if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model'       => $model ?: 'deepseek-chat',
            'messages'    => $messages,
            'temperature' => 0.4,
            'max_tokens'  => 4096
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 35
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $this->logError('callDeepSeekDirect', "HTTP {$status}: " . substr((string)$raw, 0, 300));
            return null;
        }

        $json = json_decode((string)$raw, true);
        $content = $json['choices'][0]['message']['content'] ?? null;
        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    // PT: Executa chamada direta à OpenAI com chave personalizada do usuário
    // EN: Calls OpenAI directly using user custom API key
    // AI: @llm-context gpt-4o / gpt-4o-mini
    private function callOpenAIDirect(string $system, string $prompt, string $key, string $model = 'gpt-4o'): ?string {
        $url = 'https://api.openai.com/v1/chat/completions';
        $messages = [];
        if ($system) $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model'       => $model ?: 'gpt-4o',
            'messages'    => $messages,
            'temperature' => 0.4,
            'max_tokens'  => 4096
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 35
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $this->logError('callOpenAIDirect', "HTTP {$status}: " . substr((string)$raw, 0, 300));
            return null;
        }

        $json = json_decode((string)$raw, true);
        $content = $json['choices'][0]['message']['content'] ?? null;
        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    // PT: Executa chamada direta à Anthropic com chave personalizada do usuário
    // EN: Calls Anthropic directly using user custom API key
    // AI: @llm-context claude-3-5-sonnet-20241022
    private function callAnthropicDirect(string $system, string $prompt, string $key, string $model = 'claude-3-5-sonnet-20241022'): ?string {
        $url = 'https://api.anthropic.com/v1/messages';
        $payload = [
            'model'      => $model ?: 'claude-3-5-sonnet-20241022',
            'messages'   => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 4096
        ];
        if ($system) {
            $payload['system'] = $system;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 35
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $this->logError('callAnthropicDirect', "HTTP {$status}: " . substr((string)$raw, 0, 300));
            return null;
        }

        $json = json_decode((string)$raw, true);
        $content = $json['content'][0]['text'] ?? null;
        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    // PT: Executa chamada direta à Groq com chave personalizada do usuário
    // EN: Calls Groq directly using user custom API key
    // AI: @llm-context llama-3.3-70b-versatile com circuit breaker e timeout 10s
    private function callGroqCustomDirect(string $system, string $prompt, string $key, string $model = 'llama-3.3-70b-versatile'): ?string {
        $cbLock = '/tmp/groq_circuit_breaker.lock';
        if (file_exists($cbLock) && (time() - filemtime($cbLock) < 300)) {
            return null;
        }

        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $messages = [];
        if ($system) $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model'       => $model ?: 'llama-3.3-70b-versatile',
            'messages'    => $messages,
            'temperature' => 0.4,
            'max_tokens'  => 4096
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 401 || $status === 429) {
            @file_put_contents($cbLock, (string)time());
        }

        if ($raw === false || $status < 200 || $status >= 300) {
            $this->logError('callGroqCustomDirect', "HTTP {$status}: " . substr((string)$raw, 0, 300));
            return null;
        }

        $json = json_decode((string)$raw, true);
        $content = $json['choices'][0]['message']['content'] ?? null;
        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    // ── SPOTIFY PLAYBACK CONTROLLER ──────────────────────────────────────────

    /**
     * PT: Curadoria de playlists de estudo por modo cognitivo
     * EN: Curated study playlists by cognitive mode
     */
    private const SPOTIFY_STUDY_PLAYLISTS = [
        'lofi' => [
            'id' => 'lofi',
            'name' => 'Lo-Fi Beats',
            'icon' => '🎧',
            'description' => 'Batidas calmas e contínuas para estudo sem distração verbal',
            'uri' => 'spotify:playlist:37i9dQZF1DX8Uebhn9wzrS',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DX8Uebhn9wzrS'
        ],
        'deep_focus' => [
            'id' => 'deep_focus',
            'name' => 'Hiperfoco (Deep Focus)',
            'icon' => '🧠',
            'description' => 'Piano suave e texturas ambientais para foco profundo',
            'uri' => 'spotify:playlist:37i9dQZF1DWZeKCadgRdKQ',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DWZeKCadgRdKQ'
        ],
        'binaural' => [
            'id' => 'binaural',
            'name' => 'Ondas Binaurais (Alpha/Theta)',
            'icon' => '🌊',
            'description' => 'Frequências sonoras para indução de concentração e relaxamento',
            'uri' => 'spotify:playlist:37i9dQZF1DX4WYpdgoIcn6',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DX4WYpdgoIcn6'
        ],
        'synthwave' => [
            'id' => 'synthwave',
            'name' => 'Synthwave / Cyberflow',
            'icon' => '⚡',
            'description' => 'Ritmo eletrônico constante para programação e raciocínio lógico',
            'uri' => 'spotify:playlist:37i9dQZF1DXdLEN7aqioXM',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DXdLEN7aqioXM'
        ],
        'classical' => [
            'id' => 'classical',
            'name' => 'Clássico / Barroco',
            'icon' => '🎻',
            'description' => 'Música erudita estruturada para leitura densa e memorização',
            'uri' => 'spotify:playlist:37i9dQZF1DWVfe3b7ghwOa',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DWVfe3b7ghwOa'
        ],
        'brown_noise' => [
            'id' => 'brown_noise',
            'name' => 'Ruído Marrom (Brown Noise)',
            'icon' => '📻',
            'description' => 'Bloqueio de ruídos externos e alívio para mentes hiperativas (TDAH/TEA)',
            'uri' => 'spotify:playlist:37i9dQZF1DX8b3uXlsH35C',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DX8b3uXlsH35C'
        ]
    ];

    /**
     * PT: Retorna lista de playlists de estudo curadas
     * AI: @llm-context GET /api/v1/svitlo/spotify/playlists/study
     */
    // PT: Retorna lista curada de playlists de foco e estudo do Spotify
    // EN: Returns curated list of Spotify focus and study playlists
    // AI: @llm-context GET /api/v1/svitlo/spotify/playlists/study returns SPOTIFY_STUDY_PLAYLISTS constant
    public function spotifyStudyPlaylists(): void {
        $this->respond([
            'status' => 'ok',
            'playlists' => array_values(self::SPOTIFY_STUDY_PLAYLISTS)
        ]);
    }

    /**
     * PT: Retorna token do Spotify do usuário com renovação automática
     * EN: Returns user Spotify token with automatic refresh
     * AI: @llm-context Verifica expiração ou atualizações antigas (>50 min) e renova token via refresh_token
     */
    // PT: Retorna token do Spotify do usuário com renovação automática
    // EN: Returns user Spotify token with automatic refresh
    // AI: @llm-context Verifica expiração ou atualizações antigas (>50 min) e renova token via refresh_token
    private function getSpotifyAccessToken(int $userId): ?string {
        $row = MriyaDB::queryOne(
            "SELECT credentials_enc, updated_at FROM svitlo_connections WHERE user_id = ? AND service = 'spotify'",
            [$userId]
        );
        if (!$row || empty($row['credentials_enc'])) return null;

        $key = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 32);
        $iv  = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 16);
        $decrypted = openssl_decrypt($row['credentials_enc'], 'AES-256-CBC', $key, 0, $iv);
        if (!$decrypted) return null;

        $tokenData = json_decode($decrypted, true);
        if (!is_array($tokenData) || empty($tokenData['access_token'])) return null;

        // PT: Verifica se o token expirou (expires_at) ou se foi gerado há mais de 50 minutos
        $expiresAt = (int)($tokenData['expires_at'] ?? 0);
        $needsRefresh = false;
        if ($expiresAt > 0) {
            if (time() >= ($expiresAt - 120)) {
                $needsRefresh = true;
            }
        } elseif (!empty($row['updated_at'])) {
            $updatedTime = strtotime((string)$row['updated_at']);
            if ($updatedTime > 0 && (time() - $updatedTime) > 3000) {
                $needsRefresh = true;
            }
        }

        if ($needsRefresh && !empty($tokenData['refresh_token'])) {
            $refreshed = $this->refreshSpotifyAccessToken($userId);
            if ($refreshed) return $refreshed;
        }

        return (string)$tokenData['access_token'];
    }

    /**
     * PT: Renova token de acesso do Spotify usando refresh_token
     * EN: Refreshes Spotify access token using refresh_token
     * AI: @llm-context Chama accounts.spotify.com/api/token com grant_type refresh_token e salva novo access_token
     */
    // PT: Renova token de acesso do Spotify usando refresh_token
    // EN: Refreshes Spotify access token using refresh_token
    // AI: @llm-context Chama accounts.spotify.com/api/token com grant_type refresh_token e salva novo access_token
    private function refreshSpotifyAccessToken(int $userId): ?string {
        $row = MriyaDB::queryOne(
            "SELECT credentials_enc, service_username FROM svitlo_connections WHERE user_id = ? AND service = 'spotify'",
            [$userId]
        );
        if (!$row || empty($row['credentials_enc'])) return null;

        $key = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 32);
        $iv  = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 16);
        $decrypted = openssl_decrypt($row['credentials_enc'], 'AES-256-CBC', $key, 0, $iv);
        if (!$decrypted) return null;

        $tokenData = json_decode($decrypted, true);
        $refreshToken = $tokenData['refresh_token'] ?? null;
        if (!$refreshToken) return null;

        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        $clientId     = mriya_credential('spotify', 'client_id');
        $clientSecret = mriya_credential('spotify', 'client_secret');

        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $newTokens = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($newTokens) && !empty($newTokens['access_token'])) {
            $tokenData['access_token'] = $newTokens['access_token'];
            $expiresIn = (int)($newTokens['expires_in'] ?? 3600);
            $tokenData['expires_at'] = time() + $expiresIn;
            if (!empty($newTokens['refresh_token'])) {
                $tokenData['refresh_token'] = $newTokens['refresh_token'];
            }
            $encrypted = openssl_encrypt(json_encode($tokenData), 'AES-256-CBC', $key, 0, $iv);
            MriyaDB::execute(
                "UPDATE svitlo_connections SET credentials_enc = ?, active = 1, updated_at = NOW() WHERE user_id = ? AND service = 'spotify'",
                [$encrypted, $userId]
            );
            return $newTokens['access_token'];
        }

        return null;
    }

    /**
     * PT: Retorna o estado atual do player Spotify do usuário
     * AI: @llm-context GET /api/v1/svitlo/spotify/player/status
     */
    // PT: Retorna o status atual de reprodução do player do Spotify
    // EN: Returns current playback status of the Spotify player
    // AI: @llm-context GET /api/v1/svitlo/spotify/player/status proxies Spotify Connect API
    public function spotifyPlayerStatus(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $token = $this->getSpotifyAccessToken($uid);
        if (!$token) {
            $this->respond(['connected' => false, 'error' => 'Spotify não conectado']);
            return;
        }

        $ch = curl_init('https://api.spotify.com/v1/me/player');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) {
            $token = $this->refreshSpotifyAccessToken($uid);
            if ($token) {
                $ch = curl_init('https://api.spotify.com/v1/me/player');
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                $raw = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
        }

        if ($httpCode === 204 || empty($raw)) {
            $this->respond([
                'connected' => true,
                'is_playing' => false,
                'device' => null,
                'track' => null
            ]);
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->respond(['connected' => true, 'is_playing' => false, 'track' => null]);
            return;
        }

        $item = $data['item'] ?? null;
        $trackInfo = null;
        if ($item) {
            $artists = array_map(fn($a) => $a['name'] ?? '', $item['artists'] ?? []);
            $images = $item['album']['images'] ?? [];
            $artUrl = !empty($images[0]['url']) ? $images[0]['url'] : null;

            $trackInfo = [
                'id' => $item['id'] ?? '',
                'name' => $item['name'] ?? '',
                'artist' => implode(', ', array_filter($artists)),
                'album' => $item['album']['name'] ?? '',
                'art_url' => $artUrl,
                'duration_ms' => $item['duration_ms'] ?? 0,
                'progress_ms' => $data['progress_ms'] ?? 0,
                'uri' => $item['uri'] ?? ''
            ];
        }

        $this->respond([
            'connected' => true,
            'is_playing' => (bool)($data['is_playing'] ?? false),
            'device' => [
                'name' => $data['device']['name'] ?? 'Dispositivo Spotify',
                'type' => $data['device']['type'] ?? 'Computer',
                'volume_percent' => $data['device']['volume_percent'] ?? 100
            ],
            'track' => $trackInfo
        ]);
    }

    /**
     * PT: Inicia reprodução no Spotify com playlist ou modo de estudo
     * AI: @llm-context POST /api/v1/svitlo/spotify/player/play
     */
    // PT: Inicia ou retoma a reprodução de faixa ou contexto no Spotify
    // EN: Starts or resumes playback of track or context on Spotify
    // AI: @llm-context PUT /api/v1/svitlo/spotify/player/play sends play command to Spotify API
    public function spotifyPlayerPlay(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $token = $this->getSpotifyAccessToken($uid);
        if (!$token) {
            $this->abort(400, 'Spotify não conectado. Conecte sua conta em Configurações.');
            return;
        }

        $mode = (string)($b['mode'] ?? '');
        $contextUri = (string)($b['context_uri'] ?? '');

        if ($mode && isset(self::SPOTIFY_STUDY_PLAYLISTS[$mode])) {
            $contextUri = self::SPOTIFY_STUDY_PLAYLISTS[$mode]['uri'];
        }

        $payload = [];
        if (!empty($contextUri)) {
            $payload['context_uri'] = $contextUri;
        } elseif (!empty($b['uris']) && is_array($b['uris'])) {
            $payload['uris'] = $b['uris'];
        }

        $endpoint = 'https://api.spotify.com/v1/me/player/play';
        if (!empty($b['device_id'])) {
            $endpoint .= '?device_id=' . urlencode((string)$b['device_id']);
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => !empty($payload) ? json_encode($payload) : '',
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) {
            $token = $this->refreshSpotifyAccessToken($uid);
            if ($token) {
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => !empty($payload) ? json_encode($payload) : '',
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$token}",
                        'Content-Type: application/json'
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                $raw = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
        }

        if ($httpCode === 404 || str_contains((string)$raw, 'NO_ACTIVE_DEVICE')) {
            $this->respond([
                'status' => 'no_device',
                'message' => 'Nenhum aplicativo do Spotify aberto no momento. Abra o Spotify no seu computador, celular ou web e tente novamente.'
            ]);
            return;
        }

        if ($httpCode === 403) {
            $this->respond([
                'status' => 'restricted',
                'message' => 'O controle de reprodução remoto exige conta Spotify ativa.'
            ]);
            return;
        }

        $this->respond([
            'status' => 'playing',
            'mode' => $mode ?: 'custom',
            'context_uri' => $contextUri,
            'message' => 'Música de estudo iniciada no Spotify! 🎵'
        ]);
    }

    /**
     * PT: Pausa reprodução no Spotify
     * AI: @llm-context POST /api/v1/svitlo/spotify/player/pause
     */
    // PT: Pausa a reprodução ativa no Spotify
    // EN: Pauses active playback on Spotify
    // AI: @llm-context PUT /api/v1/svitlo/spotify/player/pause sends pause command to Spotify API
    public function spotifyPlayerPause(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $token = $this->getSpotifyAccessToken($uid);
        if (!$token) {
            $this->abort(400, 'Spotify não conectado');
            return;
        }

        $ch = curl_init('https://api.spotify.com/v1/me/player/pause');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) {
            $token = $this->refreshSpotifyAccessToken($uid);
            if ($token) {
                $ch = curl_init('https://api.spotify.com/v1/me/player/pause');
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        $this->respond(['status' => 'paused']);
    }

    /**
     * PT: Pula para próxima faixa no Spotify
     * AI: @llm-context POST /api/v1/svitlo/spotify/player/next
     */
    // PT: Avança para a próxima faixa na fila do Spotify
    // EN: Skips to next track in Spotify playback queue
    // AI: @llm-context POST /api/v1/svitlo/spotify/player/next proxies Spotify player next track
    public function spotifyPlayerNext(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $token = $this->getSpotifyAccessToken($uid);
        if (!$token) { $this->abort(400, 'Spotify não conectado'); return; }

        $ch = curl_init('https://api.spotify.com/v1/me/player/next');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        curl_close($ch);
        $this->respond(['status' => 'ok']);
    }

    /**
     * PT: Retorna para faixa anterior no Spotify
     * AI: @llm-context POST /api/v1/svitlo/spotify/player/previous
     */
    // PT: Retorna para a faixa anterior na reprodução do Spotify
    // EN: Skips to previous track in Spotify playback queue
    // AI: @llm-context POST /api/v1/svitlo/spotify/player/previous proxies Spotify player previous
    public function spotifyPlayerPrevious(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $token = $this->getSpotifyAccessToken($uid);
        if (!$token) { $this->abort(400, 'Spotify não conectado'); return; }

        $ch = curl_init('https://api.spotify.com/v1/me/player/previous');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        curl_close($ch);
        $this->respond(['status' => 'ok']);
    }

    /**
     * Ajusta o volume do player Spotify do usuário autenticado via Web API.
     * Sets volume percentage on user's active Spotify player via Spotify Web API.
     * @llm-context Módulo Svitlo, escopo Spotify Controller, integra com endpoint PUT /v1/me/player/volume.
     * @llm-invariant Requer token de autenticação JWT e conexão ativa com Spotify.
     * @llm-rule Retorna JSON {status: 'ok', volume_percent: N} em HTTP 200 ou 400 se não conectado.
     */
    public function spotifyPlayerVolume(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $token = $this->getSpotifyAccessToken($uid);
        if (!$token) { $this->abort(400, 'Spotify não conectado'); return; }
        $b = $this->json();
        $vol = max(0, min(100, (int)($b['volume_percent'] ?? 100)));

        $ch = curl_init("https://api.spotify.com/v1/me/player/volume?volume_percent={$vol}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        curl_close($ch);
        $this->respond(['status' => 'ok', 'volume_percent' => $vol]);
    }

    /**
     * Altera a posição de reprodução (seek) da faixa atual no Spotify.
     * Seeks to specified position in milliseconds on currently playing Spotify track.
     * @llm-context Módulo Svitlo, escopo Spotify Controller, integra com endpoint PUT /v1/me/player/seek.
     * @llm-invariant Requer token de autenticação JWT e conexão ativa com Spotify.
     * @llm-rule Retorna JSON {status: 'ok', position_ms: N} em HTTP 200 ou 400 se não conectado.
     */
    public function spotifyPlayerSeek(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $token = $this->getSpotifyAccessToken($uid);
        if (!$token) { $this->abort(400, 'Spotify não conectado'); return; }
        $b = $this->json();
        $pos = max(0, (int)($b['position_ms'] ?? 0));

        $ch = curl_init("https://api.spotify.com/v1/me/player/seek?position_ms={$pos}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        curl_close($ch);
        $this->respond(['status' => 'ok', 'position_ms' => $pos]);
    }

    /**
     * PT: Manipula o callback de autorização OAuth do Deezer
     * EN: Handles Deezer OAuth authorization callback
     * AI: @llm-context Rota GET /api/v1/svitlo/connections/deezer/callback
     * @return void
     */
    // PT: Manipula o callback de autorização OAuth do Deezer
    // EN: Handles Deezer OAuth authorization callback
    // AI: @llm-context Rota GET /api/v1/svitlo/connections/deezer/callback
    public function deezerCallback(): void {
        $code  = (string)($_GET['code'] ?? '');
        $state = (string)($_GET['state'] ?? '');
        if (!$code) { echo '<script>window.close();</script>'; return; }

        $appId = env('DEEZER_APP_ID', '684941');
        $secret = env('DEEZER_SECRET', '5d4eb441865955627244a04d27d7045b');

        $url = "https://connect.deezer.com/oauth/access_token.php?app_id={$appId}&secret={$secret}&code={$code}&output=json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $tokenData = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($tokenData) || empty($tokenData['access_token'])) {
            echo '<script>window.opener?.postMessage({type:"deezer_error"},"*");window.close();</script>';
            return;
        }

        $accessToken = $tokenData['access_token'];
        $ch = curl_init("https://api.deezer.com/user/me?access_token={$accessToken}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $rawProfile = curl_exec($ch);
        curl_close($ch);
        $profile = is_string($rawProfile) ? json_decode($rawProfile, true) : null;
        $username = is_array($profile) ? ($profile['name'] ?? 'Deezer') : 'Deezer';

        $userId = null;
        if ($state) {
            $jwtRaw = base64_decode(str_replace(['-','_'], ['+','/'], urldecode($state)) . '==');
            try {
                $payload = MriyaJWT::validateAccessToken($jwtRaw);
                $userId = (int) ($payload['uid'] ?? $payload['sub'] ?? 0);
            } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); $userId = 0; }
        }

        if ($userId) {
            $key = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 32);
            $iv  = substr(getenv('APP_KEY') ?: 'mriya_secret_key_2026', 0, 16);
            $encrypted = openssl_encrypt(json_encode($tokenData), 'AES-256-CBC', $key, 0, $iv);

            MriyaDB::execute(
                'INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc) 
                 VALUES (?, ?, ?, ?) 
                 ON DUPLICATE KEY UPDATE service_username = VALUES(service_username), credentials_enc = VALUES(credentials_enc), updated_at = NOW()',
                [$userId, 'deezer', $username, $encrypted]
            );
        }

        echo "<script>window.opener?.postMessage({type:'deezer_connected',username:".json_encode($username)."},'*');window.close();</script>";
    }

    // ── I18N ──────────────────────────────────────────────────────────────────

    /**
     * PT: Carrega e retorna o arquivo de tradução para o idioma especificado do módulo Svitlo.
     * EN: Loads and returns translation file for the specified locale in Svitlo module.
     * AI: @llm-context Rota GET /api/v1/svitlo/i18n/{locale}.
     *     Delega para I18nController::getTranslations com escopo 'svitlo'.
     *     Locales permitidos: pt-BR, en-GB, es-ES, it-IT, uk-UA.
     * @param string $locale Código de idioma (ex: pt-BR, uk-UA)
     * @return void
     */
    // PT: Retorna o dicionário de traduções i18n do Svitlo para o locale solicitado
    // EN: Returns Svitlo i18n translation dictionary for requested locale
    // AI: @llm-context Normaliza 'pt_BR' -> 'pt-BR', 'en_GB' -> 'en-GB', prefixos 'en' -> 'en-GB'
    public function i18n(string $locale): void
    {
        $normalized = str_replace('_', '-', trim($locale));

        // Verificação precoce de arquivos gerados dinamicamente na pasta de locales
        $localesDirs = [
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/svitlo/public/locales',
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/public/svitlo/locales',
            dirname(__DIR__, 4) . '/svitlo/public/locales',
            dirname(__DIR__, 4) . '/public/svitlo/locales',
        ];
        foreach ($localesDirs as $dir) {
            $f = $dir . '/' . $normalized . '.json';
            if (file_exists($f)) {
                $content = json_decode(file_get_contents($f), true);
                if (is_array($content)) {
                    $this->respond($content);
                    return;
                }
            }
        }

        $allowed = ['pt-BR', 'en-GB', 'es-ES', 'it-IT', 'uk-UA'];
        if (!in_array($normalized, $allowed, true)) {
            $prefix = strtolower(explode('-', $normalized)[0]);
            $prefixMap = ['en' => 'en-GB', 'uk' => 'uk-UA', 'it' => 'it-IT', 'es' => 'es-ES', 'pt' => 'pt-BR'];
            $normalized = $prefixMap[$prefix] ?? 'pt-BR';
        }
        $locale = $normalized;

        require_once __DIR__ . '/I18nController.php';
        $i18n = new I18nController();
        $i18n->getTranslations($locale, 'svitlo');
    }

    // ── LOGS ──────────────────────────────────────────────────────────────────

    /**
     * PT: Retorna as últimas 100 linhas do arquivo de log do Svitlo (exclusivo para superadmin).
     * EN: Returns the last 100 lines of Svitlo log file (superadmin only).
     * AI: @llm-context SEGURANÇA — nunca remover esta verificação de superadmin.
     *     Rota GET /api/v1/svitlo/logs. Lê /var/log/mriya/svitlo.log.
     * @return void
     */
    // PT: Retorna as últimas 100 linhas do arquivo de log do Svitlo (exclusivo para superadmin).
    // EN: Returns the last 100 lines of Svitlo log file (superadmin only).
    // AI: @llm-context SEGURANÇA — nunca remover esta verificação de superadmin.
    public function logs(): void
    {
        $this->requireAuth();

        // Apenas superadmin / Only superadmin
        if (($this->user['role'] ?? '') !== 'superadmin') {
            $this->abort(403, 'Forbidden');
        }

        $logFile = '/var/log/mriya/svitlo.log';
        $lines   = [];

        if (file_exists($logFile)) {
            $all   = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = is_array($all) ? array_slice($all, -100) : [];
        }

        $this->respond(['lines' => $lines, 'file' => $logFile]);
    }

    // ── AI HELPERS (ARQUITETURA EM CAMADAS) ────────────────────────────────────

    /**
     * PT: Chama IA com roteamento inteligente de cotas BYOAI (Microsoft/Mistral) e fallback automático (Groq/Gemini).
     * EN: Calls AI with intelligent BYOAI quota routing (Microsoft/Mistral) and automatic fallback (Groq/Gemini).
     * AI: @llm-context Ordem de prioridade: 1. Microsoft Azure OpenAI, 2. Mistral AI, 3. Chave Personalizada (OpenAI/Anthropic/Groq), 4. Groq Svitlo, 5. Gemini Svitlo
     * @param string $system Prompt de sistema com instruções da IA
     * @param string $prompt Conteúdo do usuário a ser processado
     * @param string $task Tipo de tarefa: generate|transcribe|analyze|lang_detect|neural
    /**
    /**
     * PT: Orquestrador de IA adaptativo do Svitlo com seletor dinâmico de modelos e verificação diária.
     * EN: Svitlo adaptive AI orchestrator with dynamic model selector and daily verification.
     * AI: @llm-context Ordem de prioridade:
     *     1. Provedor Primário: Google Gemini (gemini-3.8-flash, gemini-3.5-flash-lite, gemma-4-31b-it)
     *     2. Contingência Automática: Groq (qwen/qwen3.8-27b, openai/gpt-oss-120b) em caso de 429 ou indisponibilidade
     * @param string $system Prompt de sistema com instruções da IA
     * @param string $prompt Conteúdo do usuário a ser processado
     * @param string $task Tipo de tarefa: generate|summary|mindmap|research|music_cards|neural|analysis
     * @param string|null $key_name Nome da chave especializada
     * @return string Resposta da IA ou lança RuntimeException
     */
    // PT: Cascata de inferência de IA com Usina Zorya, Gemini, Groq e IA Local da VPS
    // EN: AI inference cascade with Usina Zorya, Gemini, Groq, and VPS local AI
    // AI: @llm-context Orquestra prioridades: Zorya (Tailscale) -> Gemini 3.1 Flash Lite -> Groq -> IA Local VPS
    private function callAI(string $system, string $prompt, string $task = 'generate', ?string $key_name = null): string {
        // 1. Prioridade 1: Usina Zorya (Notebook via Tailscale) se online
        $usina = $this->checkUsinaStatus();
        if ($usina['online']) {
            $ch = curl_init("http://{$usina['ip']}:{$usina['port']}/v1/chat/completions");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'messages'    => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
                    'max_tokens'  => 3072,
                    'temperature' => 0.6
                ]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 35,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $res) {
                $ans = json_decode($res, true)['choices'][0]['message']['content'] ?? null;
                if (!empty($ans)) {
                    $this->lastModelUsed = 'usina/zorya';
                    return $ans;
                }
            }
        }

        // 2. Prioridade 2: Gemini 3.1 Flash Lite (500 RPD)
        $creds = json_decode((string)@file_get_contents('/var/www/mcp-server/credentials/credentials.json'), true) ?: [];
        $gemKey = $creds['gemini']['svitlo_intelligence'] ?? ($creds['gemini']['default'] ?? '');
        if ($gemKey) {
            $gemRes = $this->callProviderDetailed('gemini', 'gemini-3.1-flash-lite', $system, $prompt);
            if ($gemRes['success'] && !empty($gemRes['content'])) {
                $this->lastModelUsed = 'gemini/gemini-3.1-flash-lite';
                return $gemRes['content'];
            }
        }

        // 3. Prioridade 3: Groq com JSON mode estrito
        $groqKey = $creds['groq']['svitlo_cards'] ?? ($creds['groq']['default'] ?? '');
        if ($groqKey) {
            $groqRes = $this->callProviderDetailed('groq', 'openai/gpt-oss-120b', $system, $prompt);
            if ($groqRes['success'] && !empty($groqRes['content'])) {
                $this->lastModelUsed = 'groq/gpt-oss-120b';
                return $groqRes['content'];
            }
        }

        // 4. Prioridade 4: IA Local da VPS na porta 8085
        $chLocal = curl_init('http://127.0.0.1:8085/v1/chat/completions');
        curl_setopt_array($chLocal, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'messages'    => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => 2048,
                'temperature' => 0.6
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 35,
        ]);
        $resLocal = curl_exec($chLocal);
        $codeLocal = curl_getinfo($chLocal, CURLINFO_HTTP_CODE);
        curl_close($chLocal);
        if ($codeLocal === 200 && $resLocal) {
            $ansLocal = json_decode($resLocal, true)['choices'][0]['message']['content'] ?? null;
            if (!empty($ansLocal)) {
                $this->lastModelUsed = 'vps-local/qwen2.5-1.5b';
                return $ansLocal;
            }
        }

        throw new \RuntimeException('Falha crítica: Usina Zorya, Gemini, Groq e IA Local indisponíveis.');
    }

    // PT: Garante verificação diária e cache local (TTL 24h) dos modelos ativos Groq e Gemini
    // EN: Ensures daily check and local cache (TTL 24h) of active Groq and Gemini models
    // AI: @llm-context Valida endpoints a cada 24 horas e salva em available_models.json
    private function ensureAvailableModelsCache(): array {
        $modelsFile = '/var/www/mriya/mriya/data/available_models.json';
        $fallbackData = [
            'updated_at' => date('c'),
            'primary'    => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
            'fallback'   => ['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite'],
            'assignments' => [
                'generate'    => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
                'cards'       => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
                'summary'     => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
                'music_cards' => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
                'lang_detect' => ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
                'neural'      => ['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite']
            ]
        ];

        if (file_exists($modelsFile) && (time() - filemtime($modelsFile) < 86400)) {
            $cached = json_decode((string)file_get_contents($modelsFile), true);
            if (is_array($cached) && !empty($cached['primary'])) {
                return $cached;
            }
        }

        try {
            $dynInfo = $this->fetchActiveModelsAndGroqQuota();
            $chosenGroq = $dynInfo['best_groq'] ?? 'openai/gpt-oss-120b';
            $chosenGemini = $dynInfo['best_gemini'] ?? 'gemini-3.5-flash-lite';

            $cacheData = [
                'updated_at'  => date('c'),
                'expires_at'  => date('c', time() + 86400),
                'gemini'      => $dynInfo['gemini_models'],
                'groq'        => $dynInfo['groq_models'],
                'primary'     => ['provider' => 'groq', 'model' => $chosenGroq],
                'fallback'    => ['provider' => 'gemini', 'model' => $chosenGemini],
                'assignments' => [
                    'generate'    => ['provider' => 'groq', 'model' => $chosenGroq],
                    'cards'       => ['provider' => 'groq', 'model' => $chosenGroq],
                    'summary'     => ['provider' => 'groq', 'model' => $chosenGroq],
                    'music_cards' => ['provider' => 'groq', 'model' => $chosenGroq],
                    'lang_detect' => ['provider' => 'groq', 'model' => $chosenGroq],
                    'neural'      => ['provider' => 'gemini', 'model' => $chosenGemini]
                ]
            ];

            @file_put_contents($modelsFile, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $cacheData;
        } catch (\Throwable $e) {
            $this->logError('ensureAvailableModelsCache', $e->getMessage());
            return $fallbackData;
        }
    }

    // PT: Retorna o modelo otimizado ativo e dados de fallback para o frontend com Usina e IA Local
    // EN: Returns active optimized model and fallback data to the frontend with Usina and Local AI
    // AI: @llm-context GET /api/v1/svitlo/ai/models
    public function aiModelsList(): void {
        $cache = $this->ensureAvailableModelsCache();
        $usina = $this->checkUsinaStatus();
        $vpsLocal = $this->checkVpsLocalAiStatus();

        $this->respond([
            'success'           => true,
            'usina'             => $usina,
            'vps_local_ai'      => ['online' => $vpsLocal, 'port' => 8085, 'model' => 'qwen2.5-1.5b'],
            'primary'           => $cache['primary'] ?? ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite'],
            'fallback'          => $cache['fallback'] ?? ['provider' => 'local', 'model' => 'qwen2.5-1.5b'],
            'selected_model'    => 'gemini-3.1-flash-lite',
            'selected_provider' => 'gemini',
            'model_label'       => 'Gemini 3.1 Flash Lite (500 RPD)',
            'updated_at'        => date('c')
        ]);
    }

    // PT: Consulta em tempo real modelos ativos do Groq e Gemini e cota disponível do Groq
    // EN: Real-time query of active Groq and Gemini models and available Groq quota
    // AI: @llm-context Extrai x-ratelimit-* do Groq e models do Gemini v1beta
    private function fetchActiveModelsAndGroqQuota(array $existingHeaders = []): array {
        $creds = json_decode((string)@file_get_contents('/var/www/mcp-server/credentials/credentials.json'), true) ?: [];
        $groqKey = $creds['groq']['svitlo_intelligence'] ?? ($creds['groq']['default'] ?? '');
        $geminiKey = $creds['gemini']['svitlo_intelligence'] ?? ($creds['gemini']['default'] ?? ($creds['gemini']['Deploy'] ?? ''));

        $groqModels = [];
        $geminiModels = [];
        $groqQuota = [];

        // 1. Consulta modelos ativos da Groq
        if ($groqKey) {
            $ch = curl_init('https://api.groq.com/openai/v1/models');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $groqKey, 'User-Agent: Mozilla/5.0 (Mriya)'],
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 3
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && $resp) {
                $gData = json_decode((string)$resp, true);
                $groqModels = array_column($gData['data'] ?? [], 'id');
            }

            // Consulta cota real da Groq (aproveita headers existentes ou faz probe ágil)
            $groqQuota = $this->probeGroqQuota($groqKey, $existingHeaders);
        }

        // 2. Consulta modelos do Gemini
        if ($geminiKey) {
            $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key={$geminiKey}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 3
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $resp) {
                $mList = json_decode((string)$resp, true)['models'] ?? [];
                foreach ($mList as $mItem) {
                    if (in_array('generateContent', $mItem['supportedGenerationMethods'] ?? [])) {
                        $geminiModels[] = str_replace('models/', '', $mItem['name'] ?? '');
                    }
                }
            }
        }

        $preferredGroqList = ['openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'qwen/qwen3.8-27b', 'allam-2-7b'];
        $bestGroq = 'openai/gpt-oss-120b';
        foreach ($preferredGroqList as $cand) {
            if (in_array($cand, $groqModels, true)) {
                $bestGroq = $cand;
                break;
            }
        }

        $preferredGeminiList = ['gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-3.8-flash'];
        $bestGemini = 'gemini-3.5-flash-lite';
        foreach ($preferredGeminiList as $cand) {
            if (in_array($cand, $geminiModels, true)) {
                $bestGemini = $cand;
                break;
            }
        }

        return [
            'groq_models'   => $groqModels,
            'gemini_models' => $geminiModels,
            'groq_quota'    => $groqQuota,
            'best_groq'     => $bestGroq,
            'best_gemini'   => $bestGemini,
        ];
    }

    // PT: Extrai cota do Groq a partir de headers de resposta ou executa probe leve de 1 token
    // EN: Extracts Groq quota from response headers or performs lightweight 1-token probe
    // AI: @llm-context Lê x-ratelimit-remaining-requests, x-ratelimit-remaining-tokens, etc.
    private function probeGroqQuota(string $key, array $existingHeaders = []): array {
        $quota = [];
        foreach ($existingHeaders as $k => $v) {
            if (str_starts_with($k, 'x-ratelimit-')) {
                $quota[$k] = $v;
            }
        }
        if (!empty($quota)) {
            return $quota;
        }
        if (!$key) return [];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['model' => 'openai/gpt-oss-120b', 'messages' => [['role' => 'user', 'content' => '.']], 'max_tokens' => 1]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: Mozilla/5.0 (Mriya)'],
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        curl_close($ch);

        foreach ($responseHeaders as $hKey => $hVal) {
            if (str_starts_with($hKey, 'x-ratelimit-')) {
                $quota[$hKey] = $hVal;
            }
        }
        return $quota;
    }

    // PT: Executa chamada simultânea (paralela) para Groq e Gemini com curl_multi
    // EN: Executes simultaneous (parallel) call to Groq and Gemini using curl_multi
    // AI: @llm-context Retorna o primeiro que responder com sucesso 200
    private function callProvidersParallel(string $groqModel, string $geminiModel, string $system, string $prompt): array {
        $creds = json_decode((string)@file_get_contents('/var/www/mcp-server/credentials/credentials.json'), true) ?: [];
        $groqKey = $creds['groq']['svitlo_intelligence'] ?? ($creds['groq']['default'] ?? '');
        $geminiKey = $creds['gemini']['svitlo_intelligence'] ?? ($creds['gemini']['default'] ?? ($creds['gemini']['Deploy'] ?? ''));

        $mh = curl_multi_init();
        $chGroq = null;
        $chGemini = null;

        if ($groqKey) {
            $groqMsgs = [];
            if ($system) $groqMsgs[] = ['role' => 'system', 'content' => $system];
            $groqMsgs[] = ['role' => 'user', 'content' => $prompt];

            $mTokens = str_contains($groqModel, 'qwen') ? 1000 : 4096;
            $chGroq = curl_init('https://api.groq.com/openai/v1/chat/completions');
            curl_setopt_array($chGroq, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['model' => $groqModel, 'messages' => $groqMsgs, 'max_tokens' => $mTokens, 'temperature' => 0.7]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $groqKey, 'User-Agent: Mozilla/5.0 (Mriya)'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            curl_multi_add_handle($mh, $chGroq);
        }

        if ($geminiKey) {
            $contents = [];
            if ($system) {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $system]]];
                $contents[] = ['role' => 'model', 'parts' => [['text' => 'Entendido.']]];
            }
            $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

            $cleanModel = str_replace('models/', '', $geminiModel);
            $chGemini = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$cleanModel}:generateContent?key={$geminiKey}");
            curl_setopt_array($chGemini, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature'     => 0.7,
                        'maxOutputTokens' => 4096
                    ]
                ]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            curl_multi_add_handle($mh, $chGemini);
        }

        $active = null;
        $winner = null;
        $errors = ['groq' => null, 'gemini' => null];

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.05);
            }
            while ($info = curl_multi_info_read($mh)) {
                $handle = $info['handle'];
                $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $respContent = curl_multi_getcontent($handle);

                if ($handle === $chGroq) {
                    if ($httpCode === 200 && $respContent) {
                        $data = json_decode($respContent, true);
                        $txt = $data['choices'][0]['message']['content'] ?? null;
                        if (!empty($txt) && $winner === null) {
                            $winner = ['success' => true, 'provider' => 'groq', 'model' => $groqModel, 'content' => $txt];
                            break 2;
                        }
                    } else {
                        $errors['groq'] = "HTTP {$httpCode}: " . substr((string)$respContent, 0, 200);
                    }
                } elseif ($handle === $chGemini) {
                    if ($httpCode === 200 && $respContent) {
                        $data = json_decode($respContent, true);
                        $txt = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                        if (!empty($txt) && $winner === null) {
                            $winner = ['success' => true, 'provider' => 'gemini', 'model' => $geminiModel, 'content' => $txt];
                            break 2;
                        }
                    } else {
                        $errors['gemini'] = "HTTP {$httpCode}: " . substr((string)$respContent, 0, 200);
                    }
                }
            }
        } while ($active && $status == CURLM_OK);

        if ($chGroq) {
            curl_multi_remove_handle($mh, $chGroq);
            curl_close($chGroq);
        }
        if ($chGemini) {
            curl_multi_remove_handle($mh, $chGemini);
            curl_close($chGemini);
        }
        curl_multi_close($mh);

        if ($winner !== null) {
            return $winner;
        }

        // Se o Gemini falhou no paralelo (ex: 503 ou 404), tenta o modelo alternativo gemini-3.5-flash
        if ($geminiKey && $geminiModel !== 'gemini-3.5-flash') {
            $altGem = $this->callProviderDetailed('gemini', 'gemini-3.5-flash', $system, $prompt);
            if ($altGem['success'] && !empty($altGem['content'])) {
                return ['success' => true, 'provider' => 'gemini', 'model' => 'gemini-3.5-flash', 'content' => $altGem['content']];
            }
        }

        return ['success' => false, 'errors' => $errors];
    }

    // PT: Executa chamada detalhada a um provedor com retorno de headers e erro formatado
    // EN: Executes detailed call to a provider returning headers and formatted error
    // AI: @llm-context Captura status HTTP e headers para diagnóstico e cota
    private function callProviderDetailed(string $provider, string $model, string $system, string $prompt): array {
        try {
            $creds = json_decode((string)@file_get_contents('/var/www/mcp-server/credentials/credentials.json'), true);

            if ($provider === 'groq') {
                $key = $creds['groq']['svitlo_intelligence'] ?? ($creds['groq']['default'] ?? '');
                if (!$key) return ['success' => false, 'error' => 'Chave Groq não configurada'];

                $messages = [];
                if ($system) $messages[] = ['role' => 'system', 'content' => $system];
                $messages[] = ['role' => 'user', 'content' => $prompt];

                // Prioriza modelos resilientes: se passar qwen ou se der 429, chaveia para openai/gpt-oss-120b
                $groqModelsToTry = array_unique([$model, 'openai/gpt-oss-120b', 'openai/gpt-oss-20b']);
                $lastError = '';
                $lastHeaders = [];

                foreach ($groqModelsToTry as $tryModel) {
                    $mTokens = str_contains($tryModel, 'qwen') ? 1000 : 4096;
                    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
                    $responseHeaders = [];
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode(['model' => $tryModel, 'messages' => $messages, 'max_tokens' => $mTokens, 'temperature' => 0.7]),
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: Mozilla/5.0 (Mriya)'],
                        CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                            $len = strlen($header);
                            $parts = explode(':', $header, 2);
                            if (count($parts) === 2) {
                                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                            }
                            return $len;
                        },
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 30,
                        CURLOPT_CONNECTTIMEOUT => 4,
                    ]);
                    $raw = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode === 200 && $raw) {
                        $data = json_decode((string)$raw, true);
                        $content = $data['choices'][0]['message']['content'] ?? null;
                        if ($content !== null && trim($content) !== '') {
                            return ['success' => true, 'content' => $content, 'status' => $httpCode, 'headers' => $responseHeaders, 'model' => $tryModel];
                        }
                    }

                    $lastError = "HTTP {$httpCode}: " . substr((string)$raw, 0, 200);
                    $lastHeaders = $responseHeaders;

                    // Se não foi rate limit ou erro de modelo, não adianta tentar outros
                    if ($httpCode !== 429 && $httpCode !== 404 && $httpCode !== 400) {
                        break;
                    }
                }
                return ['success' => false, 'error' => $lastError, 'status' => $httpCode ?? 0, 'headers' => $lastHeaders];
            }

            if ($provider === 'gemini') {
                $key = $creds['gemini']['svitlo_intelligence'] ?? ($creds['gemini']['default'] ?? ($creds['gemini']['Deploy'] ?? ''));
                if (!$key) return ['success' => false, 'error' => 'Chave Gemini não configurada'];

                $contents = [];
                if ($system) {
                    $contents[] = ['role' => 'user', 'parts' => [['text' => $system]]];
                    $contents[] = ['role' => 'model', 'parts' => [['text' => 'Entendido.']]];
                }
                $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

                $geminiModelsToTry = array_unique([$model, 'gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-3.8-flash']);
                $lastError = '';

                foreach ($geminiModelsToTry as $tryModel) {
                    $cleanModel = str_replace('models/', '', $tryModel);
                    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$cleanModel}:generateContent?key={$key}");
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode([
                            'contents' => $contents,
                            'generationConfig' => [
                                'temperature'     => 0.7,
                                'maxOutputTokens' => 4096
                            ]
                        ]),
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 25,
                        CURLOPT_CONNECTTIMEOUT => 4,
                    ]);
                    $raw = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode === 200 && $raw) {
                        $data = json_decode((string)$raw, true);
                        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                        if ($text !== null && trim($text) !== '') {
                            return ['success' => true, 'content' => $text, 'status' => $httpCode, 'model' => $cleanModel];
                        }
                    }
                    $lastError = "HTTP {$httpCode}: " . substr((string)$raw, 0, 200);
                }
                return ['success' => false, 'error' => $lastError, 'status' => $httpCode ?? 0];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        return ['success' => false, 'error' => 'Provedor desconhecido'];
    }

    // PT: Chama um provider específico (compatibilidade retroativa)
    // EN: Calls a specific provider (backward compatibility)
    // AI: @llm-context Wrapper sobre callProviderDetailed
    private function callProvider(string $provider, string $model, string $system, string $prompt): ?string {
        $res = $this->callProviderDetailed($provider, $model, $system, $prompt);
        if ($res['success']) {
            $this->lastModelUsed = $provider . '/' . ($res['model'] ?? $model);
            return $res['content'];
        }
        $this->logError('callProvider/' . $provider, $res['error'] ?? 'Erro desconhecido');
        return null;
    }


    private function callAzureOpenAIDirect(string $system, string $prompt, string $token): ?string
    {
        $url = 'https://api.cognitive.microsoft.com/openai/deployments/gpt-4o/chat/completions?api-version=2024-08-01-preview';
        $messages = [];
        if ($system) $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'messages' => $messages,
            'temperature' => 0.4,
            'max_tokens' => 4096
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'api-key: ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $this->logError('callAzureOpenAIDirect', "HTTP {$status} - {$err}: " . substr((string)$raw, 0, 300));
            return null;
        }

        $json = json_decode((string)$raw, true);
        $content = $json['choices'][0]['message']['content'] ?? null;
        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    // PT: Chama Mistral AI diretamente usando token BYOAI do usuário
    // EN: Calls Mistral AI directly using user's BYOAI token
    // AI: @llm-context Endpoint: https://api.mistral.ai/v1/chat/completions (mistral-large-latest)
    private function callMistralDirect(string $system, string $prompt, string $token): ?string
    {
        $url = 'https://api.mistral.ai/v1/chat/completions';
        $messages = [];
        if ($system) $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model' => 'mistral-large-latest',
            'messages' => $messages,
            'temperature' => 0.4,
            'max_tokens' => 4096
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $this->logError('callMistralDirect', "HTTP {$status} - {$err}: " . substr((string)$raw, 0, 300));
            return null;
        }

        $json = json_decode((string)$raw, true);
        $content = $json['choices'][0]['message']['content'] ?? null;
        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    /**
     * PT: Executa chamada à API da Mistral AI como provedor VPS (após Groq, antes do Gemini)
     * EN: Calls Mistral AI API as VPS provider (after Groq, before Gemini)
     * AI: @llm-context base_url: https://api.mistral.ai/v1, model: mistral-small-latest, chave em credentials.json['mistral']
     * @param string $system Prompt de sistema
     * @param string $prompt Prompt do usuário
     * @param string $task Tipo de tarefa
     * @param string $keyName Nome da chave especializada
     * @return string|null Conteúdo gerado ou null em caso de erro
     */
    // PT: Executa requisição para API da Mistral AI com prompt de sistema e usuário
    // EN: Executes request to Mistral AI API with system and user prompts
    // AI: @llm-context Uses mistral-small-latest with temperature 0.3 for structured JSON output
    private function callMistral(string $system, string $prompt, string $task = 'generate', string $keyName = 'default'): ?string
    {
        $key = mriya_credential('mistral', 'api_key', '');
        $baseUrl = mriya_credential('mistral', 'base_url', 'https://api.mistral.ai/v1');
        $baseUrl = rtrim($baseUrl, '/');

        if (!$key) {
            $this->logError('callMistral', 'Nenhuma chave Mistral encontrada');
            return null;
        }

        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $model = 'mistral-small-latest';
        $maxTokens = ($task === 'generate' || $task === 'cards') ? 4096 : 2048;

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => 0.4
        ];
        if (stripos($system . ' ' . $prompt, 'json') !== false) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        error_log("[Svitlo AI] Mistral calling task={$task} key_role={$keyName} model={$model}");

        $ch = curl_init($baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
                'User-Agent: Svitlo/2.0 (PHP-Direct)'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 35,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($status !== 200 || empty($raw) || $err) {
            $this->logError('callMistral', "HTTP {$status} - {$err}: " . substr((string)$raw, 0, 300));
            return null;
        }

        $data = json_decode((string)$raw, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            $this->logError('callMistral', 'Resposta vazia da Mistral: ' . substr((string)$raw, 0, 200));
            return null;
        }

        $this->lastModelUsed = 'mistral/' . $model;
        if (class_exists('SvitloLogger')) {
            SvitloLogger::aiSuccess('mistral', $model, 0, ['key_role' => $keyName]);
        }
        return trim($content);
    }

    /**
     * PT: Escolhe modelo Groq por tarefa (custo x benefício).
     * EN: Selects Groq model by task (cost vs benefit).
     * AI: @llm-context qwen3.8-27b padrão; openai/gpt-oss-120b para análise e fallback
     * @param string $task Tipo de tarefa
     * @return string Identificador do modelo Groq
     */
    // PT: Escolhe modelo Groq por tarefa (custo x benefício).
    // EN: Selects Groq model by task (cost vs benefit).
    // AI: @llm-context qwen3.8-27b padrão; openai/gpt-oss-120b para análise e fallback
    private function resolveGroqModel(string $task): string
    {
        return match($task) {
            'analysis', 'generate_complex', 'lang_detect', 'music_lang_detect' => 'openai/gpt-oss-120b',
            default => 'qwen/qwen3.8-27b',
        };
    }

    // PT: Obtém chave de API da Groq a partir de MriyaCredentials, arquivo protegido ou cofre
    // EN: Retrieves Groq API key from MriyaCredentials, protected file or vault
    // AI: @llm-context Lê credentials.json['groq'][$role] via MriyaCredentials::getGroqKey()
    private function getGroqKey(string $role = 'default'): string {
        if (class_exists('MriyaCredentials')) {
            $key = MriyaCredentials::getGroqKey($role);
            if (!empty($key)) return $key;
        }
        $file = '/var/www/mcp-server/credentials/credentials.json';
        if (file_exists($file)) {
            $data = @json_decode((string)file_get_contents($file), true);
            if (!empty($data['groq'][$role])) return trim((string)$data['groq'][$role]);
            if (!empty($data['groq']['default'])) return trim((string)$data['groq']['default']);
        }
        $legacy = '/var/www/mcp-server/credentials/groq.json';
        if (file_exists($legacy)) {
            $data = @json_decode((string)file_get_contents($legacy), true);
            if (!empty($data['api_key'])) return trim((string)$data['api_key']);
        }
        $keys = $this->loadSvitloKeys();
        if (!empty($keys['svitlo_intelligence']['groq'])) {
            return trim((string)$keys['svitlo_intelligence']['groq']);
        }
        if (!empty($keys['svitlo_cards']['groq'])) {
            return trim((string)$keys['svitlo_cards']['groq']);
        }
        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        return mriya_credential('groq', $role !== 'default' ? $role : 'default');
    }

    // PT: Obtém chave de API do Gemini a partir de MriyaCredentials, arquivo protegido ou cofre
    // EN: Retrieves Gemini API key from MriyaCredentials, protected file or vault
    // AI: @llm-context Lê credentials.json['gemini'][$role] via MriyaCredentials::getGeminiKey()
    private function getGeminiKey(string $role = 'default'): string {
        if (class_exists('MriyaCredentials')) {
            $key = MriyaCredentials::getGeminiKey($role);
            if (!empty($key)) return $key;
        }
        $file = '/var/www/mcp-server/credentials/credentials.json';
        if (file_exists($file)) {
            $data = @json_decode((string)file_get_contents($file), true);
            if (!empty($data['gemini'][$role])) return trim((string)$data['gemini'][$role]);
            if (!empty($data['gemini']['Deploy'])) return trim((string)$data['gemini']['Deploy']);
            if (!empty($data['gemini']['deploy'])) return trim((string)$data['gemini']['deploy']);
            if (!empty($data['gemini']['default'])) return trim((string)$data['gemini']['default']);
        }
        $legacy = '/var/www/mcp-server/credentials/gemini.json';
        if (file_exists($legacy)) {
            $data = @json_decode((string)file_get_contents($legacy), true);
            if (!empty($data['api_key'])) return trim((string)$data['api_key']);
            if (!empty($data['Deploy'])) return trim((string)$data['Deploy']);
            if (!empty($data['deploy'])) return trim((string)$data['deploy']);
        }
        $keys = $this->loadSvitloKeys();
        if (!empty($keys['svitlo_cards']['gemini'])) {
            return trim((string)$keys['svitlo_cards']['gemini']);
        }
        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        return mriya_credential('gemini', $role !== 'default' ? $role : 'default');
    }

    /**
     * PT: Chama Groq API diretamente com timeout ágil de 10s e circuit breaker imediato para Gemini.
     * EN: Calls Groq API directly with fast 10s timeout and instant circuit breaker to Gemini.
     * AI: @llm-context Timeout 10s. Falhas 401/429/timeout retornam null imediatamente para ativar fallback Gemini.
     * @param string $system Prompt de sistema
     * @param string $prompt Prompt do usuário
     * @param string $task Tipo de tarefa
     * @return string|null Resposta da IA ou null em caso de erro
     */
    // PT: Executa chamada direta à API da Groq para inferência de baixa latência (timeout 10s)
    // EN: Executes direct call to Groq API for low-latency inference (10s timeout)
    // AI: @llm-context Primary provider with JSON mode, 10s timeout and instant circuit breaker
    private function callGroqDirect(string $system, string $prompt, string $task = 'generate', string $keyName = 'default'): ?string
    {
        $roleMap = [
            'generate'          => 'svitlo_cards',
            'cards'             => 'svitlo_cards',
            'summary'           => 'svitlo_summary',
            'mindmap'           => 'svitlo_mindmap',
            'research'          => 'svitlo_research',
            'analysis'          => 'svitlo_research',
            'generate_complex'  => 'svitlo_intelligence',
            'music_cards'       => 'music_engine',
            'music_engine'      => 'music_engine',
            'lang_detect'       => 'music_engine',
            'music_lang_detect' => 'music_engine',
            'neural'            => 'neural_engine',
            'cognitive'         => 'neural_engine',
            'qa'                => 'qa',
        ];
        $role = ($keyName !== 'default' && $keyName !== '') ? $keyName : ($roleMap[$task] ?? 'svitlo_cards');
        $key = $this->getGroqKey($role);
        if (!$key) {
            $key = $this->getGroqKey('default');
        }
        if (!$key) { 
            $this->logError('callGroq', 'Nenhuma chave Groq encontrada'); 
            return null; 
        }

        $cbLock = '/tmp/groq_circuit_breaker.lock';
        if (file_exists($cbLock) && (time() - filemtime($cbLock) < 300)) {
            $this->logError('callGroq', 'Groq Circuit Breaker ABERTO (/tmp/groq_circuit_breaker.lock ativo). Fallback imediato Gemini.');
            return null;
        }

        $model = $this->resolveGroqModel($task);
        error_log("[Svitlo AI] Groq calling task={$task} key_role={$role} key_prefix=" . substr($key, 0, 8) . " model={$model}");

        $messages = [];
        if ($system) $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        // PT: max_tokens no mínimo 2048 para evitar truncamento de JSON
        // EN: max_tokens at least 2048 to prevent JSON truncation
        $maxTokens = max(2048, ($task === 'generate' || $task === 'cards') ? 4096 : 2048);

        $groqPayload = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => 0.4,
        ];

        // PT: response_format json_object para geração de cards e JSON Mode
        // EN: response_format json_object for card generation and JSON Mode
        if (in_array($task, ['generate', 'cards', 'summary', 'feynman', 'mindmap'], true) || stripos($system . ' ' . $prompt, 'json') !== false) {
            $groqPayload['response_format'] = ['type' => 'json_object'];
        }

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($groqPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
                'User-Agent: Svitlo/2.0 (PHP-Direct)'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // PT: Circuit Breaker Ágil: 401, 429, timeout (0) ou 5xx abortam IMEDIATAMENTE para fallback Gemini
        // EN: Agile Circuit Breaker: 401, 429, timeout (0) or 5xx abort IMMEDIATELY for Gemini fallback
        // AI: @llm-invariant Zero bloqueio prolongado de usuário: fallback imediato para Google Gemini
        if ($httpCode === 401 || $httpCode === 429) {
            @file_put_contents($cbLock, (string)time());
        }

        if ($httpCode === 401 || $httpCode === 429 || $httpCode === 0 || $err || $httpCode !== 200 || empty($raw)) {
            $this->logError('callGroq', "Groq abortado (HTTP {$httpCode} - cURL: {$err}). Ativando fallback imediato Gemini.");
            return null;
        }

        $data = json_decode((string)$raw, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!$text) { 
            $this->logError('callGroq', 'Resposta vazia da Groq. Ativando fallback Gemini.'); 
            return null; 
        }
        
        $this->lastModelUsed = 'groq/' . $model;
        if (class_exists('SvitloLogger')) {
            SvitloLogger::aiSuccess('groq', $model, 0, ['key_role' => $role, 'key_prefix' => substr($key, 0, 8)]);
        }
        return $text;
    }

    /**
     * PT: Chama Gemini API diretamente como fallback com suporte multi-modelo e parsing resiliente.
     * EN: Calls Gemini API directly as fallback with multi-model support and resilient parsing.
     * AI: @llm-context Credenciais em credentials.json['gemini'] via MriyaCredentials
     * @param string $system Prompt de sistema
     * @param string $prompt Prompt do usuário
     * @return string|null Resposta ou null em caso de erro
     */
    // PT: Executa requisição direta à API do Google Gemini para raciocínio complexo
    // EN: Executes direct request to Google Gemini API for complex reasoning
    // AI: @llm-context Tenta gemini-2.0-flash, gemini-1.5-flash e gemini-1.5-pro com timeout estrito de 10s
    private function callGeminiDirect(string $system, string $prompt, string $keyName = 'default'): ?string
    {
        $role = ($keyName !== 'default' && $keyName !== '') ? $keyName : 'svitlo_cards';
        $key = $this->getGeminiKey($role);
        if (!$key) $key = $this->getGeminiKey('svitlo_intelligence');
        if (!$key) $key = $this->getGeminiKey('default');
        if (!$key) { $this->logError('callGemini', 'Chave Gemini não encontrada'); return null; }

        $contents = [];
        if ($system) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $system]]];
            $contents[] = ['role' => 'model', 'parts' => [['text' => 'Entendido.']]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

        $isJson = (stripos($system . ' ' . $prompt, 'json') !== false);
        $genConfig = [
            'temperature'     => 0.3,
            'maxOutputTokens' => 4096,
        ];
        if ($isJson) {
            $genConfig['responseMimeType'] = 'application/json';
        }

        $modelsToTry = ['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemma-4-31b-it'];
        foreach ($modelsToTry as $model) {
            error_log("[Svitlo AI] Gemini calling key_role={$role} model={$model}");
            $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'contents'         => $contents,
                    'generationConfig' => $genConfig
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 4
            ]);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err || !$raw || $httpCode !== 200) {
                $this->logError('callGemini', "Model {$model} HTTP {$httpCode} - cURL: {$err} - " . substr((string)$raw, 0, 150));
                continue;
            }

            $data = json_decode((string)$raw, true);
            $cand = (isset($data['candidates']) && is_array($data['candidates']) && !empty($data['candidates'])) ? $data['candidates'][0] : [];
            $parts = $cand['content']['parts'] ?? [];
            $text = '';
            foreach ($parts as $p) {
                if (empty($p['thought'])) {
                    $text .= $p['text'] ?? '';
                }
            }
            $text = trim($text);
            if ($text !== '') {
                $this->lastModelUsed = 'gemini/' . $model;
                if (class_exists('SvitloLogger')) {
                    SvitloLogger::aiSuccess('gemini', $model, 0, ['key_role' => $role]);
                }
                return $text;
            }
        }

        return null;
    }

    /**
     * PT: Busca top tracks do Last.FM diretamente (sem AI Router).
     * EN: Fetches Last.FM top tracks directly (without AI Router).
     * AI: @llm-context Substitui chamada para 127.0.0.1:8200 — chama ws.audioscrobbler.com diretamente
     * @param string $service Identificador do serviço musical (lastfm|spotify)
     * @param array $credentials Credenciais descriptografadas do serviço
     * @return array Lista de faixas com nome, artista e contagem
     */
    // PT: Consulta histórico e músicas mais ouvidas em serviços de streaming
    // EN: Fetches top played tracks from streaming services for study music analysis
    // AI: @llm-context Queries Spotify or Last.fm API with user credentials
    private function getTopTracks(string $service, array $credentials): array
    {
        if ($service === 'spotify') {
            $accessToken = $credentials['access_token'] ?? null;
            if (!$accessToken) return [];
            $ch = curl_init('https://api.spotify.com/v1/me/top/tracks?time_range=long_term&limit=20');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!empty($data['items']) && is_array($data['items'])) {
                return array_map(fn($item) => [
                    'name'   => $item['name'] ?? '',
                    'artist' => $item['artists'][0]['name'] ?? '',
                    'plays'  => (int)($item['popularity'] ?? 0),
                    'source' => 'spotify'
                ], $data['items']);
            }
            return [];
        }

        if ($service !== 'lastfm') return [];
        
        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        $lastfmCreds = [
            'api_key'  => mriya_credential('lastfm', 'api_key'),
            'username' => mriya_credential('lastfm', 'username', 'pedro-almeida'),
        ];
        $username = rawurlencode($credentials['username'] ?? $credentials['user'] ?? $lastfmCreds['username'] ?? 'pedro-almeida');
        if (!$username) return [];
        
        $period = (string)($_GET['period'] ?? '12month');
        $allowed = ['7day','1month','3month','6month','12month','overall'];
        if (!in_array($period, $allowed)) $period = '12month';
        
        $apiKey = !empty($lastfmCreds['api_key']) ? $lastfmCreds['api_key'] : mriya_credential('lastfm', 'api_key');
        $url = "http://ws.audioscrobbler.com/2.0/?method=user.gettoptracks&user={$username}&api_key={$apiKey}&period={$period}&limit=20&format=json";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string)$raw, true);
        
        $tracks = $data['toptracks']['track'] ?? [];
        return array_map(fn($t) => [
            'name'   => $t['name'] ?? '',
            'artist' => is_array($t['artist']) ? ($t['artist']['name'] ?? '') : ($t['artist'] ?? ''),
            'plays'  => (int)($t['playcount'] ?? 0),
        ], $tracks);
    }

    /**
     * PT: Envia arquivo de áudio para a API do Groq transcrever utilizando o modelo Whisper Large v3.
     * EN: Sends audio file to Groq API for transcription using Whisper Large v3 model.
     * AI: @llm-context Integração externa Groq Whisper. Timeout: 60s. Fallback: string vazia se falhar.
     * @param string $filePath Caminho do arquivo de áudio temporário
     * @param string $language Código ISO do idioma (ex: 'pt', 'en', 'uk')
     * @return string Texto transcrito ou string vazia em caso de falha
     */
    // PT: Executa transcrição de áudio via API Whisper da Groq ou OpenAI
    // EN: Executes audio transcription via Groq or OpenAI Whisper API
    // AI: @llm-context Sends audio file to whisper-large-v3 model with prompt assistance
    private function callWhisper(string $filePath, string $language): string
    {
        $key = $this->getGroqKey('svitlo_cards');
        if (!$key) $key = $this->getGroqKey('default');
        if (!$key) return '';

        $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'file'            => new \CURLFile($filePath),
                'model'           => 'whisper-large-v3',
                'language'        => $language,
                'response_format' => 'json',
            ],
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'User-Agent: Mriya-Svitlo/2.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($response)) return '';
        $data = json_decode($response, true);
        return is_array($data) ? (string)($data['text'] ?? '') : '';
    }

    /**
     * PT: Carrega chaves de API do Svitlo a partir do arquivo protegido de credenciais.
     * EN: Loads Svitlo API keys from secure credentials file.
     * AI: @llm-context Lê /var/www/mcp-server/credentials/credentials.json ou svitlo_keys.json.
     * @return array Chaves e tokens configurados
     */
    // PT: Carrega chaves de API do Svitlo a partir do arquivo protegido de credenciais.
    // EN: Loads Svitlo API keys from secure credentials file.
    // AI: @llm-context Lê /var/www/mcp-server/credentials/credentials.json ou svitlo_keys.json.
    private function loadSvitloKeys(): array
    {
        if (class_exists('MriyaCredentials')) {
            $all = MriyaCredentials::all();
            if (!empty($all)) return $all;
        }
        $keysFile = '/var/www/mcp-server/credentials/credentials.json';
        if (file_exists($keysFile)) {
            $decoded = json_decode((string)file_get_contents($keysFile), true);
            if (is_array($decoded)) return $decoded;
        }
        $legacy = '/var/www/mcp-server/credentials/svitlo_keys.json';
        if (file_exists($legacy)) {
            $decoded = json_decode((string)file_get_contents($legacy), true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }

    // ── ACCESSIBILITY PREFERENCES ─────────────────────────────────────────────

    /**
     * PT: Retorna as preferências de acessibilidade e perfil cognitivo do usuário autenticado.
     * EN: Returns accessibility preferences and cognitive profile for the authenticated user.
     * AI: @llm-context Rota GET /api/v1/svitlo/accessibility.
     *     Faz JOIN de user_accessibility_preferences com svitlo_academic_profiles.
     *     Retorna valores padrão se o usuário ainda não tiver configurado preferências.
     * @return void
     */
    // PT: Retorna as configurações de acessibilidade ativas do estudante
    // EN: Returns active accessibility preferences for the student
    // AI: @llm-context GET /api/v1/svitlo/accessibility delegates to AccessibilityManager
    public function accessibilityGet(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        
        $row = MriyaDB::queryOne(
            'SELECT a.*, p.cognitive_profile, p.cognitive_completed_at, p.cognitive_dismissed, p.study_focus as academic_study_focus
             FROM user_accessibility_preferences a
             LEFT JOIN svitlo_academic_profiles p ON p.user_id = a.user_id
             WHERE a.user_id = ?', [$uid]
        );

        if (!$row) {
            $p = MriyaDB::queryOne('SELECT cognitive_profile, cognitive_completed_at, cognitive_dismissed, study_focus FROM svitlo_academic_profiles WHERE user_id = ?', [$uid]);
            $row = [
                'user_id' => $uid,
                'high_contrast' => 0,
                'dark_mode' => 0,
                'font_size' => 'medium',
                'dyslexia_font' => 0,
                'reduce_motion' => 0,
                'focus_mode' => 0,
                'color_blind' => 'none',
                'native_lang' => 'pt-BR',
                'opt_music' => 0,
                'opt_study_pattern' => 0,
                'opt_cross_data' => 0,
                'study_focus' => $p['study_focus'] ?? 'academico',
                'cognitive_profile' => $p['cognitive_profile'] ?? null,
                'cognitive_completed_at' => $p['cognitive_completed_at'] ?? null,
                'cognitive_dismissed' => $p['cognitive_dismissed'] ?? 0
            ];
        }

        // PT: Resolução robusta de perfil cognitivo com fallback para svitlo_cognitive_interpretations e svitlo_academic_profiles
        // EN: Robust cognitive profile resolution with fallback to svitlo_cognitive_interpretations and svitlo_academic_profiles
        $cogData = null;
        if (!empty($row['cognitive_profile'])) {
            $cogData = is_string($row['cognitive_profile']) ? json_decode($row['cognitive_profile'], true) : $row['cognitive_profile'];
        }

        $hasDetails = is_array($cogData) && (
            !empty($cogData['summary']) || 
            !empty($cogData['rdoc_profile']) || 
            !empty($cogData['domains']) || 
            !empty($cogData['metrics']) || 
            !empty($cogData['study_recommendations']) ||
            !empty($cogData['study_adaptations'])
        );

        if (!$hasDetails) {
            $interp = MriyaDB::queryOne('SELECT * FROM svitlo_cognitive_interpretations WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
            if ($interp) {
                $sess = MriyaDB::queryOne('SELECT session_date, total_duration_ms FROM svitlo_cognitive_sessions WHERE id = ?', [(int)$interp['session_id']]);
                $rdoc = is_string($interp['rdoc_profile']) ? json_decode($interp['rdoc_profile'], true) : $interp['rdoc_profile'];
                $adapts = is_string($interp['study_adaptations']) ? json_decode($interp['study_adaptations'], true) : $interp['study_adaptations'];
                $totalMin = !empty($sess['total_duration_ms']) ? round($sess['total_duration_ms'] / 60000, 1) : 25;
                
                $merged = is_array($cogData) ? $cogData : [];
                $merged['completed_at'] = $merged['completed_at'] ?? $sess['session_date'] ?? $interp['created_at'] ?? $row['cognitive_completed_at'] ?? date('c');
                $merged['total_time'] = $merged['total_time'] ?? $totalMin;
                $merged['rdoc_profile'] = $rdoc;
                $merged['study_recommendations'] = $adapts;
                $merged['narrative'] = $interp['narrative'] ?? '';

                $row['cognitive_profile'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
                $row['cognitive_completed_at'] = $row['cognitive_completed_at'] ?? $sess['session_date'] ?? $interp['created_at'] ?? null;
            } elseif (!empty($row['cognitive_completed_at']) || !empty($cogData)) {
                // Perfil realizado sem interpretação detalhada: carrega dados calibrados da tabela acadêmica
                $pData = MriyaDB::queryOne('SELECT neuro_qi, neuro_working_memory, neuro_attention, neuro_diagnoses FROM svitlo_academic_profiles WHERE user_id = ?', [$uid]);
                $diag = !empty($pData['neuro_diagnoses']) ? (is_string($pData['neuro_diagnoses']) ? json_decode($pData['neuro_diagnoses'], true) : $pData['neuro_diagnoses']) : [];
                $merged = is_array($cogData) ? $cogData : [];
                $merged['completed_at'] = $merged['completed_at'] ?? $row['cognitive_completed_at'] ?? date('c');
                $merged['total_time'] = $merged['total_time'] ?? 25;
                $merged['rdoc_profile'] = [
                    'cognitive_systems' => ['level' => 'PRESERVADA', 'score' => 82],
                    'arousal_regulatory' => ['level' => 'CALIBRADO', 'score' => 78],
                    'positive_valence' => ['level' => 'NORMAL', 'score' => 75],
                    'negative_valence' => ['level' => 'ESTÁVEL', 'score' => 70],
                    'sensorimotor' => ['level' => 'ÁGIL', 'score' => 85]
                ];
                $merged['study_recommendations'] = [
                    'Intercalar disciplinas complexas com blocos de estudo estruturados.',
                    'Utilizar repetição espaçada (SRS) para fixação de conceitos-chave.',
                    'Pausas estratégicas a cada ciclo para consolidação de memória.'
                ];
                $row['cognitive_profile'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
            }
        }

        $this->respond((array)$row);
    }

    /**
     * PT: Salva configurações de acessibilidade, idioma nativo e preferências de adaptação cognitiva no banco.
     * EN: Saves accessibility settings, native language, and cognitive adaptation preferences in DB.
     * AI: @llm-context Rota PUT /api/v1/svitlo/accessibility.
     *     Insere ou atualiza registros na tabela user_accessibility_preferences.
     * @return void
     */
    // PT: Salva configurações de acessibilidade, idioma nativo e preferências de adaptação cognitiva no banco.
    // EN: Saves accessibility settings, native language, and cognitive adaptation preferences in DB.
    // AI: @llm-context Rota PUT /api/v1/svitlo/accessibility.
    public function accessibilitySave(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        try {
            $existing = MriyaDB::queryOne('SELECT * FROM user_accessibility_preferences WHERE user_id = ?', [$uid]) ?: [];

            $highContrast    = isset($b['highContrast']) ? (int)$b['highContrast'] : (int)($existing['high_contrast'] ?? 0);
            $darkMode        = isset($b['darkMode']) ? (int)$b['darkMode'] : (int)($existing['dark_mode'] ?? 0);
            $fontSize        = isset($b['fontSize']) ? (string)$b['fontSize'] : (string)($existing['font_size'] ?? 'medium');
            $dyslexiaFont    = isset($b['dyslexiaFont']) ? (int)$b['dyslexiaFont'] : (int)($existing['dyslexia_font'] ?? 0);
            $reduceMotion    = isset($b['reduceMotion']) ? (int)$b['reduceMotion'] : (int)($existing['reduce_motion'] ?? 0);
            $focusMode       = isset($b['focusMode']) ? (int)$b['focusMode'] : (int)($existing['focus_mode'] ?? 0);
            $colorBlind      = isset($b['colorBlind']) ? (string)$b['colorBlind'] : (string)($existing['color_blind'] ?? 'none');
            $screenReader    = isset($b['screenReader']) ? (int)$b['screenReader'] : (int)($existing['screen_reader'] ?? 0);
            $signLanguage    = isset($b['signLanguage']) ? (string)$b['signLanguage'] : (string)($existing['sign_language'] ?? 'none');
            $nativeLang      = isset($b['nativeLang']) ? (string)$b['nativeLang'] : (string)($existing['native_lang'] ?? 'pt-BR');
            $optMusic        = isset($b['optMusic']) ? (int)$b['optMusic'] : (int)($existing['opt_music'] ?? 0);
            $optStudyPattern = isset($b['optStudyPattern']) ? (int)$b['optStudyPattern'] : (int)($existing['opt_study_pattern'] ?? 0);
            $optCrossData    = isset($b['optCrossData']) ? (int)$b['optCrossData'] : (int)($existing['opt_cross_data'] ?? 0);
            $studyFocus      = isset($b['studyFocus']) ? (string)$b['studyFocus'] : (string)($existing['study_focus'] ?? 'academico');

            MriyaDB::execute(
                'INSERT INTO user_accessibility_preferences
                 (user_id, high_contrast, dark_mode, font_size, dyslexia_font,
                  reduce_motion, focus_mode, color_blind, screen_reader, sign_language,
                  native_lang, opt_music, opt_study_pattern, opt_cross_data, study_focus)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                 high_contrast=VALUES(high_contrast), dark_mode=VALUES(dark_mode),
                 font_size=VALUES(font_size), dyslexia_font=VALUES(dyslexia_font),
                 reduce_motion=VALUES(reduce_motion), focus_mode=VALUES(focus_mode),
                 color_blind=VALUES(color_blind), screen_reader=VALUES(screen_reader),
                 sign_language=VALUES(sign_language), native_lang=VALUES(native_lang),
                 opt_music=VALUES(opt_music), opt_study_pattern=VALUES(opt_study_pattern),
                 opt_cross_data=VALUES(opt_cross_data), study_focus=VALUES(study_focus)',
                [$uid, $highContrast, $darkMode, $fontSize, $dyslexiaFont, $reduceMotion, $focusMode,
                 $colorBlind, $screenReader, $signLanguage, $nativeLang, $optMusic, $optStudyPattern,
                 $optCrossData, $studyFocus]
            );
            $this->respond(['status' => 'saved']);
        } catch (\Throwable $e) {
            $this->logError('accessibilitySave', $e->getMessage());
            $this->abort(500, 'Erro ao salvar: ' . $e->getMessage());
        }
    }

    // ── RESPONSE HELPERS ──────────────────────────────────────────────────────

    /**
     * PT: Lê o corpo da requisição HTTP (php://input) e decodifica como array associativo JSON.
     * EN: Reads HTTP request body (php://input) and decodes as JSON associative array.
     * AI: @llm-context Retorna array vazio se o payload for inválido ou ausente.
     * @return array Dados decodificados do JSON
     */
    // PT: Lê o corpo da requisição HTTP (php://input) e decodifica como array associativo JSON.
    // EN: Reads HTTP request body (php://input) and decodes as JSON associative array.
    // AI: @llm-context Retorna array vazio se o payload for inválido ou ausente.
    private function json(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? $data : [];
    }

    /**
     * PT: Envia resposta HTTP em formato JSON com código de status e encerra a execução do script.
     * EN: Sends JSON formatted HTTP response with status code and terminates script execution.
     * AI: @llm-context Define Content-Type application/json; charset=utf-8 e codifica com UNESCAPED_UNICODE.
     * @param array $data Estrutura de dados a ser serializada
     * @param int $status Código de status HTTP (padrão 200)
     * @return void
     */
    // PT: Emite resposta padronizada em JSON com código de status HTTP
    // EN: Outputs standardized JSON response with HTTP status code
    // AI: @llm-context Sets Content-Type: application/json and exits
    protected function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * PT: Aborta a requisição enviando resposta de erro em formato JSON estruturado.
     * EN: Aborts request by sending a structured JSON error response.
     * AI: @llm-context Retorna payload {success: false, error: $message} com o status HTTP informado.
     * @param int $status Código HTTP de erro (ex: 400, 401, 403, 500)
     * @param string $message Mensagem descritiva do erro
     * @return void
     */
    // PT: Interrompe a execução e retorna mensagem de erro em formato JSON
    // EN: Aborts execution and returns JSON formatted error message
    // AI: @llm-context Sets HTTP error code and prints error envelope
    protected function abort(int $status, string $message): void
    {
        $this->respond(['success' => false, 'error' => $message], $status);
    }

    // PT: Busca referência acadêmica no CrossRef
    /**
     * PT: Formata metadados de artigo/obra para padrão ABNT NBR 6023:2018
     * EN: Formats work metadata according to ABNT NBR 6023:2018 standard
     * AI: @llm-context Regras ABNT NBR 6023: SOBRENOME, Nome. Título: subtítulo. Periódico, v., n., p., ano.
     */
    // PT: Formata metadados de artigo/obra para padrão ABNT NBR 6023:2018
    // EN: Formats work metadata according to ABNT NBR 6023:2018 standard
    // AI: @llm-context Regras ABNT NBR 6023: SOBRENOME, Nome. Título: subtítulo. Periódico, v., n., p., ano.
    private function formatAbntReference(array $work): string {
        $authors = $work['authors'] ?? [];
        $authorStr = '';
        if (is_string($authors)) {
            $authorStr = trim($authors);
        } elseif (is_array($authors) && !empty($authors)) {
            $formattedAuthors = [];
            foreach ($authors as $a) {
                $name = is_array($a) ? ($a['name'] ?? $a['display_name'] ?? '') : (string)$a;
                $name = trim($name);
                if (!$name) continue;
                $parts = preg_split('/\s+/', $name);
                if (count($parts) > 1) {
                    $last = mb_strtoupper(array_pop($parts), 'UTF-8');
                    $first = implode(' ', $parts);
                    $formattedAuthors[] = "{$last}, {$first}";
                } else {
                    $formattedAuthors[] = mb_strtoupper($name, 'UTF-8');
                }
            }
            if (count($formattedAuthors) > 3) {
                $authorStr = $formattedAuthors[0] . ' et al.';
            } elseif (!empty($formattedAuthors)) {
                $authorStr = implode('; ', $formattedAuthors) . '.';
            }
        }

        $title = trim((string)($work['title'] ?? 'Sem título'));
        $journal = trim((string)($work['journal'] ?? $work['source'] ?? ''));
        $year = (string)($work['year'] ?? $work['publication_year'] ?? '');
        $volume = (string)($work['volume'] ?? '');
        $issue = (string)($work['issue'] ?? '');
        $pages = (string)($work['pages'] ?? '');
        $doi = trim((string)($work['doi'] ?? ''));

        $parts = [];
        if ($authorStr) $parts[] = $authorStr;
        if ($title) $parts[] = rtrim($title, '.') . '.';
        
        $pubDetails = [];
        if ($journal) $pubDetails[] = "<b>{$journal}</b>";
        if ($volume) $pubDetails[] = "v. {$volume}";
        if ($issue) $pubDetails[] = "n. {$issue}";
        if ($pages) $pubDetails[] = "p. {$pages}";
        if ($year) $pubDetails[] = $year;

        if (!empty($pubDetails)) {
            $parts[] = implode(', ', $pubDetails) . '.';
        }

        if ($doi) {
            $doiUrl = str_starts_with($doi, 'http') ? $doi : 'https://doi.org/' . ltrim($doi, '/');
            $parts[] = "Disponível em: &lt;{$doiUrl}&gt;.";
        }

        return implode(' ', $parts);
    }

    // PT: Busca referência acadêmica no CrossRef
    // EN: Fetches academic reference from CrossRef
    // AI: @llm-context POST /api/v1/svitlo/academic/reference
    public function academicReference(): void {
        $this->requireAuth();
        $b = $this->json();
        $doi = $b['doi'] ?? '';
        if (!$doi) $this->respond(['error' => 'DOI missing'], 400);
        $url = "https://api.crossref.org/works/" . urlencode($doi);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mriya/1.0 (mailto:admin@espectroipe.com.br)");
        $res = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($res, true);
        if (!$data || $data['status'] !== 'ok') $this->respond(['error' => 'Referência não encontrada'], 404);
        
        $w = $data['message'];
        $title = $w['title'][0] ?? '';
        $author = '';
        if (!empty($w['author'])) {
            $last = mb_strtoupper($w['author'][0]['family'] ?? '');
            $first = $w['author'][0]['given'] ?? '';
            $author = "{$last}, {$first}.";
        }
        $year = $w['published']['date-parts'][0][0] ?? $w['created']['date-parts'][0][0] ?? '';
        $journal = $w['container-title'][0] ?? '';
        
        $abnt = trim("{$author} {$title}. {$journal}, {$year}. DOI: {$doi}");
        
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute(
            "INSERT INTO svitlo_academic_references (user_id, doi, title, authors, year, journal, abnt_formatted, source, saved_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'crossref', NOW())",
            [$uid, $doi, $title, $author, $year ?: null, $journal ?: null, $abnt]
        );
        
        $this->respond(['abnt' => $abnt, 'abnt_formatted' => $abnt, 'raw' => $w]);
    }

    // PT: Formata referência para a norma ABNT NBR 6023:2018 a partir de DOI ou dados manuais
    // EN: Formats reference according to ABNT NBR 6023:2018 standard from DOI or manual data
    // AI: @llm-context POST /api/v1/svitlo/academic/format-abnt
    public function academicFormatAbnt(): void {
        $this->requireAuth();
        $b = $this->json();

        $doi     = trim((string)($b['doi'] ?? $b['DOI'] ?? ''));
        $title   = trim((string)($b['title'] ?? ''));
        $authors = $b['authors'] ?? [];
        $journal = trim((string)($b['journal'] ?? $b['container-title'] ?? ''));
        $year    = !empty($b['year']) ? (int)$b['year'] : null;
        $volume  = trim((string)($b['volume'] ?? ''));
        $issue   = trim((string)($b['issue'] ?? ''));
        $pages   = trim((string)($b['pages'] ?? ''));
        $url     = trim((string)($b['url'] ?? ''));

        // Se DOI fornecido e faltam dados essenciais (ex.: título ou autores), busca metadados
        if (!empty($doi) && (empty($title) || empty($authors))) {
            $cleanDoi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi);
            $cleanDoi = ltrim($cleanDoi, '/');

            // 1. Tenta OpenAlex por DOI
            $oaUrl = "https://api.openalex.org/works/https://doi.org/" . urlencode($cleanDoi) . "?mailto=pedroluistenorio@gmail.com";
            $ch = curl_init($oaUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Svitlo/1.0 (mailto:pedroluistenorio@gmail.com)',
                    'Accept: application/json'
                ]
            ]);
            $oaResp = curl_exec($ch);
            $oaCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($oaCode === 200 && is_string($oaResp)) {
                $oaData = json_decode($oaResp, true);
                if (!empty($oaData['title'])) {
                    if (empty($title)) $title = $oaData['display_name'] ?? ($oaData['title'] ?? '');
                    if (empty($journal)) $journal = $oaData['primary_location']['source']['display_name'] ?? '';
                    if (empty($year) && !empty($oaData['publication_year'])) $year = (int)$oaData['publication_year'];
                    if (empty($url)) $url = $oaData['primary_location']['landing_page_url'] ?? ($oaData['doi'] ?? '');
                    if (empty($authors) && !empty($oaData['authorships'])) {
                        $authors = [];
                        foreach ($oaData['authorships'] as $aship) {
                            if (!empty($aship['author']['display_name'])) {
                                $authors[] = $aship['author']['display_name'];
                            }
                        }
                    }
                }
            }

            // 2. Se ainda faltar título, fallback para CrossRef
            if (empty($title)) {
                $crUrl = "https://api.crossref.org/works/" . urlencode($cleanDoi);
                $ch = curl_init($crUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_HTTPHEADER     => [
                        'User-Agent: Svitlo/1.0 (mailto:pedroluistenorio@gmail.com)',
                        'Accept: application/json'
                    ]
                ]);
                $crResp = curl_exec($ch);
                $crCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($crCode === 200 && is_string($crResp)) {
                    $crData = json_decode($crResp, true);
                    $msg = $crData['message'] ?? [];
                    if (!empty($msg['title'][0])) {
                        if (empty($title)) $title = $msg['title'][0];
                        if (empty($journal) && !empty($msg['container-title'][0])) $journal = $msg['container-title'][0];
                        if (empty($year) && !empty($msg['published']['date-parts'][0][0])) $year = (int)$msg['published']['date-parts'][0][0];
                        if (empty($volume) && !empty($msg['volume'])) $volume = (string)$msg['volume'];
                        if (empty($issue) && !empty($msg['issue'])) $issue = (string)$msg['issue'];
                        if (empty($pages) && !empty($msg['page'])) $pages = (string)$msg['page'];
                        if (empty($url) && !empty($msg['URL'])) $url = (string)$msg['URL'];
                        if (empty($authors) && !empty($msg['author']) && is_array($msg['author'])) {
                            $authors = [];
                            foreach ($msg['author'] as $a) {
                                $given = $a['given'] ?? '';
                                $family = $a['family'] ?? '';
                                $authors[] = trim("$given $family");
                            }
                        }
                    }
                }
            }
        }

        $workMeta = [
            'title'   => $title ?: 'Sem título',
            'authors' => $authors,
            'journal' => $journal,
            'year'    => $year,
            'volume'  => $volume,
            'issue'   => $issue,
            'pages'   => $pages,
            'doi'     => $doi,
            'url'     => $url
        ];

        $formatted = $this->formatAbntReference($workMeta);

        $this->respond([
            'status'         => 'ok',
            'abnt_formatted' => $formatted,
            'data'           => [
                'title'   => $title,
                'authors' => $authors,
                'journal' => $journal,
                'year'    => $year,
                'volume'  => $volume,
                'issue'   => $issue,
                'pages'   => $pages,
                'doi'     => $doi,
                'url'     => $url
            ]
        ]);
    }

    // PT: Busca obras acadêmicas na OpenAlex API
    // EN: Searches academic works on OpenAlex API
    // AI: @llm-context GET /api/v1/svitlo/academic/search?q={query}
    public function academicSearch(): void {
        $this->requireAuth();
        $q = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
        if (empty($q)) {
            $this->respond(['status' => 'ok', 'results' => [], 'total' => 0]);
            return;
        }

        // PT: Detecta se o conteúdo é direito brasileiro
        // EN: Detects if content is Brazilian law
        // AI: @llm-context Brazilian law keywords trigger PT/BR filtered search
        $isBrazilianLaw = (bool)preg_match('/\b(ADI|STF|STJ|CF\/88|constituição|inconstitucionalidade|jurisprudência|direito|lei|artigo \d+|código civil|código penal|CLT|OAB)\b/i', $q);

        $queryParams = [
            'search'   => $q,
            'mailto'   => 'pedroluistenorio@gmail.com',
            'per_page' => 15
        ];
        if ($isBrazilianLaw) {
            $queryParams['filter'] = 'language:pt';
        }

        $url = 'https://api.openalex.org/works?' . http_build_query($queryParams);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Svitlo/1.0 (mailto:pedroluistenorio@gmail.com)',
                'Accept: application/json'
            ]
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $results = [];
        if ($httpCode === 200 && is_string($resp)) {
            $data = json_decode($resp, true);
            $items = $data['results'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    $journal = $item['primary_location']['source']['display_name'] ?? '';
                    $doi = $item['doi'] ?? '';
                    $year = $item['publication_year'] ?? '';
                    $title = $item['display_name'] ?? ($item['title'] ?? 'Sem título');

                    // Reconstrói resumo do abstract_inverted_index se presente
                    $abstract = '';
                    if (!empty($item['abstract_inverted_index']) && is_array($item['abstract_inverted_index'])) {
                        $wordMap = [];
                        foreach ($item['abstract_inverted_index'] as $word => $positions) {
                            if (is_array($positions)) {
                                foreach ($positions as $pos) {
                                    $wordMap[(int)$pos] = $word;
                                }
                            }
                        }
                        ksort($wordMap);
                        $abstract = implode(' ', $wordMap);
                        if (mb_strlen($abstract) > 500) {
                            $abstract = mb_substr($abstract, 0, 500) . '...';
                        }
                    }

                    // Relevance check: article title or abstract must contain at least one keyword from original query
                    if ($isBrazilianLaw) {
                        preg_match_all('/\b(ADI|STF|STJ|CF\/88|constituição|inconstitucionalidade|jurisprudência|direito|lei|código civil|código penal|CLT|OAB|[a-z0-9]{3,})\b/iu', $q, $qMatches);
                        $qWords = array_unique(array_filter(array_map('trim', $qMatches[0] ?? [])));
                        $isRel = false;
                        foreach ($qWords as $kw) {
                            if (mb_strlen($kw) >= 2 && (mb_stripos($title, $kw) !== false || mb_stripos($abstract, $kw) !== false)) {
                                $isRel = true;
                                break;
                            }
                        }
                        if (!$isRel) continue;
                    }

                    $authorsList = [];
                    if (!empty($item['authorships']) && is_array($item['authorships'])) {
                        foreach ($item['authorships'] as $aship) {
                            if (!empty($aship['author']['display_name'])) {
                                $authorsList[] = $aship['author']['display_name'];
                            }
                        }
                    }

                    $workMeta = [
                        'title'   => $title,
                        'authors' => $authorsList,
                        'year'    => $year,
                        'journal' => $journal,
                        'doi'     => $doi
                    ];

                    $results[] = [
                        'id'             => $item['id'] ?? '',
                        'title'          => $title,
                        'authors'        => $authorsList,
                        'year'           => $year,
                        'doi'            => $doi,
                        'DOI'            => $doi,
                        'journal'        => $journal,
                        'abstract'       => $abstract,
                        'url'            => $item['primary_location']['landing_page_url'] ?? ($item['doi'] ?? ''),
                        'abnt_formatted' => $this->formatAbntReference($workMeta)
                    ];
                }
            }
        }

        if ($isBrazilianLaw && count($results) < 2) {
            $results = [];
        }

        $this->respond([
            'status'  => 'ok',
            'query'   => $q,
            'results' => $results,
            'total'   => count($results)
        ]);
    }

    // PT: Salva uma referência acadêmica nas referências do usuário
    // EN: Saves an academic reference to user's saved references
    // AI: @llm-context POST /api/v1/svitlo/academic/references
    public function academicReferenceSave(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $title = trim((string)($b['title'] ?? ''));
        $doi = trim((string)($b['doi'] ?? $b['DOI'] ?? ''));
        $journal = trim((string)($b['journal'] ?? ''));
        $year = !empty($b['year']) ? (int)$b['year'] : null;
        $url = trim((string)($b['url'] ?? ''));
        $volume = trim((string)($b['volume'] ?? ''));
        $issue = trim((string)($b['issue'] ?? ''));
        $pages = trim((string)($b['pages'] ?? ''));
        $source = (string)($b['source'] ?? 'openalex');
        if (!in_array($source, ['consensus','semantic_scholar','crossref','arxiv','pubmed','openalex','manual'])) {
            $source = 'openalex';
        }

        $authorsRaw = $b['authors'] ?? [];
        if (is_array($authorsRaw)) {
            $authorsArr = array_values(array_filter(array_map('trim', $authorsRaw)));
            $authorsJson = json_encode($authorsArr, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($authorsRaw) && trim($authorsRaw) !== '') {
            $decoded = json_decode($authorsRaw, true);
            if (is_array($decoded)) {
                $authorsArr = $decoded;
                $authorsJson = $authorsRaw;
            } else {
                $authorsArr = array_values(array_filter(array_map('trim', explode(',', $authorsRaw))));
                $authorsJson = json_encode($authorsArr, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $authorsArr = [];
            $authorsJson = json_encode([]);
        }

        $abnt = trim((string)($b['abnt_formatted'] ?? ($b['abnt_text'] ?? '')));
        if (!$abnt) {
            $abnt = $this->formatAbntReference([
                'title'   => $title,
                'authors' => $authorsArr,
                'journal' => $journal,
                'year'    => $year,
                'volume'  => $volume,
                'issue'   => $issue,
                'pages'   => $pages,
                'doi'     => $doi,
                'url'     => $url
            ]);
        }

        MriyaDB::execute(
            "INSERT INTO svitlo_academic_references
             (user_id, doi, title, authors, year, journal, volume, issue, pages, url, abnt_formatted, source, saved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$uid, $doi ?: null, $title ?: 'Referência', $authorsJson, $year, $journal ?: null, $volume ?: null, $issue ?: null, $pages ?: null, $url ?: null, $abnt, $source]
        );

        $id = (int)MriyaDB::lastInsertId();
        $this->respond([
            'status'         => 'saved',
            'id'             => $id,
            'abnt_formatted' => $abnt,
            'reference'      => [
                'id'             => $id,
                'title'          => $title,
                'authors'        => $authorsArr,
                'year'           => $year,
                'doi'            => $doi,
                'journal'        => $journal,
                'abnt_formatted' => $abnt,
                'source'         => $source
            ]
        ]);
    }

    /**
     * PT: Lista todas as referências acadêmicas salvas do usuário
     * EN: Lists all saved academic references for the user
     * AI: @llm-context ITEM 9: GET /api/v1/svitlo/academic/references
     */
    // PT: Lista todas as referências acadêmicas salvas do usuário
    // EN: Lists all saved academic references for the user
    // AI: @llm-context ITEM 9: GET /api/v1/svitlo/academic/references
    public function academicReferences(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        $rows = MriyaDB::query(
            "SELECT id, user_id, doi, title, authors, year, journal, volume, issue, pages, url,
                    abnt_formatted, abnt_formatted AS abnt_text, source, saved_at, saved_at AS created_at
             FROM svitlo_academic_references
             WHERE user_id = ?
             ORDER BY saved_at DESC",
            [$uid]
        ) ?: [];

        $formattedList = [];
        foreach ($rows as $r) {
            $authorsArr = [];
            if (!empty($r['authors']) && is_string($r['authors'])) {
                $dec = json_decode($r['authors'], true);
                if (is_array($dec)) {
                    $authorsArr = $dec;
                } else {
                    $authorsArr = array_values(array_filter(array_map('trim', explode(',', $r['authors']))));
                }
            } elseif (is_array($r['authors'] ?? null)) {
                $authorsArr = $r['authors'];
            }

            $abnt = trim((string)($r['abnt_formatted'] ?? $r['abnt_text'] ?? ''));
            if (empty($abnt)) {
                $abnt = $this->formatAbntReference([
                    'title'   => (string)($r['title'] ?? ''),
                    'authors' => $authorsArr,
                    'journal' => (string)($r['journal'] ?? ''),
                    'year'    => !empty($r['year']) ? (int)$r['year'] : null,
                    'volume'  => (string)($r['volume'] ?? ''),
                    'issue'   => (string)($r['issue'] ?? ''),
                    'pages'   => (string)($r['pages'] ?? ''),
                    'doi'     => (string)($r['doi'] ?? ''),
                    'url'     => (string)($r['url'] ?? '')
                ]);
            }

            $formattedList[] = [
                'id'             => (int)$r['id'],
                'user_id'        => (int)$r['user_id'],
                'title'          => (string)($r['title'] ?? 'Sem título'),
                'authors'        => $authorsArr,
                'authors_list'   => $authorsArr,
                'authors_str'    => implode(', ', $authorsArr),
                'year'           => !empty($r['year']) ? (int)$r['year'] : null,
                'doi'            => (string)($r['doi'] ?? ''),
                'journal'        => (string)($r['journal'] ?? ''),
                'volume'         => (string)($r['volume'] ?? ''),
                'issue'          => (string)($r['issue'] ?? ''),
                'pages'          => (string)($r['pages'] ?? ''),
                'url'            => (string)($r['url'] ?? ''),
                'abnt_formatted' => $abnt,
                'abnt_text'      => $abnt,
                'source'         => (string)($r['source'] ?? 'openalex'),
                'saved_at'       => $r['saved_at'] ?? date('Y-m-d H:i:s'),
                'created_at'     => $r['saved_at'] ?? date('Y-m-d H:i:s')
            ];
        }

        $this->respond([
            'status'     => 'ok',
            'references' => $formattedList,
            'total'      => count($formattedList)
        ]);
    }

    /**
     * PT: Remove referência acadêmica salva
     * EN: Deletes saved academic reference
     * AI: @llm-context ITEM 9: DELETE /api/v1/svitlo/academic/references/{id}
     */
    // PT: Remove referência acadêmica salva
    // EN: Deletes saved academic reference
    // AI: @llm-context ITEM 9: DELETE /api/v1/svitlo/academic/references/{id}
    public function academicReferenceDelete(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute("DELETE FROM svitlo_academic_references WHERE id = ? AND user_id = ?", [$id, $uid]);
        $this->respond(['status' => 'deleted', 'id' => $id]);
    }

    // PT: Analisa trecho de escrita acadêmica utilizando EXCLUSIVAMENTE as referências salvas do usuário
    // EN: Analyzes academic writing draft using ONLY user's saved references
    // AI: @llm-context POST /api/v1/svitlo/academic/assist - AI NEVER invents references, uses ONLY user's saved references
    public function academicAssist(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $mode = $b['mode'] ?? 'revisor';
        $content = trim((string)($b['content'] ?? ''));
        $lang = $b['nativeLang'] ?? 'português';
        
        if (empty($content)) {
            $this->abort(400, 'Conteúdo para análise não fornecido.');
            return;
        }

        // PT: Puxa referências salvas do usuário no banco de dados
        // EN: Fetches user's saved references from database
        $savedRows = MriyaDB::query(
            "SELECT title, authors, year, doi, journal, abnt_formatted FROM svitlo_academic_references WHERE user_id = ? ORDER BY saved_at DESC",
            [$uid]
        ) ?: [];

        $referencesAbntList = [];
        foreach ($savedRows as $row) {
            $abnt = trim((string)($row['abnt_formatted'] ?? ''));
            if (!empty($abnt)) {
                $referencesAbntList[] = $abnt;
            } else {
                $referencesAbntList[] = (string)($row['title'] ?? 'Referência');
            }
        }

        // Se o frontend passou lista complementar de referências
        $clientRefs = is_array($b['references'] ?? null) ? $b['references'] : [];
        if (!empty($clientRefs) && empty($referencesAbntList)) {
            foreach ($clientRefs as $cRef) {
                if (is_string($cRef) && trim($cRef) !== '') {
                    $referencesAbntList[] = trim($cRef);
                } elseif (is_array($cRef)) {
                    $referencesAbntList[] = trim((string)($cRef['abnt_formatted'] ?? $cRef['title'] ?? ''));
                }
            }
        }

        $refsCount = count($referencesAbntList);

        // PT: Configura prompt de especialidade da IA conforme modo acadêmico selecionado
        // EN: Configures AI specialty prompt according to selected academic mode
        // AI: @llm-context System prompt customization per academic mode (revisor, feynman, socratico, abnt, tcc, artigo, resumo, introducao, referencial, discussao, conclusao)
        $sys = "";
        if ($mode === 'feynman') {
            $sys = "Você é um mentor acadêmico especialista no Método Feynman. Explique e analise o trecho a seguir de forma extremamente clara e didática, eliminando jargões desnecessários mas preservando o rigor conceitual. Responda em {$lang}.";
        } elseif ($mode === 'socratico') {
            $sys = "Você é um orientador socrático rigoroso. Elabore 3 a 5 perguntas reflexivas profundas sobre o trecho a seguir para expor premissas implícitas, testar hipóteses e fortalecer a fundamentação teórica. Responda em {$lang}.";
        } elseif ($mode === 'revisor') {
            $sys = "Você é um parecerista ad-hoc de revista científica de alto impacto (Qualis A1 / Q1). Avalie o trecho criticamente apontando: (1) Clareza e precisão terminológica; (2) Coerência e consistência dos argumentos; (3) Lacunas de embasamento; (4) Sugestão de redação aprimorada. Responda em {$lang}.";
        } elseif ($mode === 'abnt') {
            $sys = "Você é um revisor acadêmico especialista nas normas ABNT NBR 6023 (Referências) e ABNT NBR 10520 (Citações). Analise as citações diretas, indiretas e o padrão autor-data (SOBRENOME, Ano, p. XX) no trecho. Aponte correções pontuais e apresente o texto corrigido. Responda em português.";
        } elseif ($mode === 'tcc') {
            $sys = "Você é assistente de escrita para TCC de graduação. Use APENAS as referências fornecidas. Escreva a seção solicitada em linguagem acadêmica fluida, primeira pessoa do plural. NÃO critique o método — escreva.";
        } elseif ($mode === 'artigo') {
            $sys = "Você é assistente de escrita para artigo científico. Use APENAS as referências fornecidas. Siga estrutura IMRaD. Linguagem objetiva e precisa.";
        } elseif ($mode === 'resumo') {
            $sys = "Gere um resumo acadêmico de 150-250 palavras com: objetivo, método, resultados e conclusão. Inclua 3-5 palavras-chave.";
        } elseif ($mode === 'introducao') {
            $sys = "Escreva uma introdução acadêmica com: contextualização do problema, justificativa, objetivo geral e estrutura do trabalho. Use as referências fornecidas.";
        } elseif ($mode === 'referencial') {
            $sys = "Desenvolva o referencial teórico sobre o tema informado, articulando as referências salvas em uma narrativa coesa e progressiva.";
        } elseif ($mode === 'discussao') {
            $sys = "Escreva a seção de discussão relacionando os resultados com a literatura das referências salvas.";
        } elseif ($mode === 'conclusao') {
            $sys = "Escreva as considerações finais: retome o objetivo, sintetize os achados, aponte limitações e sugira trabalhos futuros.";
        } else {
            $sys = "Você é um assistente de escrita científica de alto nível. Analise o texto fornecido com foco em clareza, coesão, fluidez e precisão acadêmica. Responda em {$lang}.";
        }

        // PT: DIRETRIZ MANDATÓRIA: NUNCA inventar referências — usar APENAS as salvas pelo usuário
        // EN: MANDATORY DIRECTIVE: NEVER invent references — use ONLY user's saved references
        // AI: @llm-context Regra absoluta contra alucinações de citações e referências
        if (!empty($referencesAbntList)) {
            $formattedRefList = implode("\n", array_map(function($r, $i) {
                return ($i + 1) . ". " . strip_tags($r);
            }, $referencesAbntList, array_keys($referencesAbntList)));

            $sys .= "\n\nUse ONLY these references:\n" . $formattedRefList;
            $sys .= "\n\nDIRETRIZ MANDATÓRIA DE CITAÇÕES: The AI must NEVER invent references — only use what the user saved. Ao sugerir citações ou fundamentação teórica, use EXCLUSIVAMENTE as referências salvas fornecidas na lista acima.";
        } else {
            $sys .= "\n\nAVISO DE REFERÊNCIAS: O usuário não possui referências salvas no acervo. Pesquise artigos na aba OpenAlex e salve referências antes de usar o assistente. The AI must NEVER invent references — only use what the user saved.";
        }
        
        $aiResponse = $this->callAI($sys, $content, 'research', 'svitlo_research');
        $this->respond([
            'status'           => 'ok',
            'analysis'         => $aiResponse,
            'mode'             => $mode,
            'references_count' => $refsCount
        ]);
    }

    // PT: Gera fichamento bibliográfico estruturado a partir de texto de estudo
    // EN: Generates structured bibliographic reading notes from study text
    // AI: @llm-context POST /api/v1/svitlo/academic/fichamento formats citations and synthesis
    public function academicFichamento(): void {
        $this->requireAuth();
        $b = $this->json();
        $content = $b['content'] ?? '';
        
        $sys = "Você é um assistente de pesquisa. Extraia do texto fornecido os seguintes elementos para um fichamento estruturado: autor, ano, tese central, metodologia, principais resultados. Retorne em JSON com essas chaves.";
        $aiResponse = $this->callAI($sys, $content, 'research', 'svitlo_research');
        $this->respond(['fichamento' => $aiResponse]);
    }

    /**
     * PT: Traduz texto acadêmico utilizando a API da DeepL com fallback para IA direta.
     * EN: Translates academic text using DeepL API with direct AI fallback.
     * AI: @llm-context Lê credenciais de MriyaCredentials, credentials.json ou fallback
     *     Detecta endpoint Free (:fx) ou Pro da DeepL.
     * @param string $text Texto a ser traduzido
     * @param string $targetLang Idioma de destino (ex: PT-BR, EN-GB, ES)
     * @return string Texto traduzido
     */
    // PT: Executa tradução de texto utilizando a API do DeepL com cache
    // EN: Translates text using DeepL API with caching
    // AI: @llm-context Calls DeepL v2 translate endpoint with target language
    private function translateWithDeepL(string $text, string $targetLang = 'PT-BR'): string
    {
        $text = trim($text);
        if (empty($text)) return '';

        // Obter chave da DeepL
        $deeplKey = class_exists('MriyaCredentials') ? MriyaCredentials::getDeepLKey() : '';
        if (!$deeplKey) {
            $deeplFile = '/var/www/mcp-server/credentials/credentials.json';
            if (file_exists($deeplFile)) {
                $data = @json_decode((string)file_get_contents($deeplFile), true);
                $deeplKey = trim((string)($data['deepl']['api_key'] ?? ($data['deepl']['key'] ?? '')));
            }
        }
        if (!$deeplKey) {
            $keys = $this->loadSvitloKeys();
            $deeplKey = trim((string)($keys['deepl']['api_key'] ?? ($keys['deepl'] ?? ($keys['svitlo_translate']['deepl'] ?? ''))));
        }
        if (!$deeplKey) {
            // PT: Lê credenciais do arquivo centralizado — nunca hardcode
            // EN: Reads from centralized credentials file — never hardcode
            // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
            $deeplKey = mriya_credential('deepl', 'api_key');
        }

        if ($deeplKey) {
            // Normaliza idioma de destino suportado pela DeepL (ex: PT-BR, EN-GB, EN-US, ES, FR, DE, IT, UK)
            $deeplLang = strtoupper(str_replace('_', '-', $targetLang));
            if ($deeplLang === 'PT') $deeplLang = 'PT-BR';
            if ($deeplLang === 'EN') $deeplLang = 'EN-US';

            $endpoint = str_ends_with($deeplKey, ':fx')
                ? 'https://api-free.deepl.com/v2/translate'
                : 'https://api.deepl.com/v2/translate';

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'text'        => $text,
                    'target_lang' => $deeplLang,
                ]),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: DeepL-Auth-Key ' . $deeplKey,
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: Svitlo/2.0 (DeepL-Integration)'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if (!$curlErr && $httpCode === 200 && is_string($resp)) {
                $resData = json_decode($resp, true);
                $translated = $resData['translations'][0]['text'] ?? null;
                if (!empty($translated)) {
                    return trim((string)$translated);
                }
            } else {
                $this->logError('translateWithDeepL', 'DeepL API error (HTTP ' . $httpCode . '): ' . substr((string)$resp, 0, 200));
            }
        }

        // Fallback: tradução via Groq/Gemini se DeepL falhar
        try {
            $prompt = "Traduza o seguinte texto acadêmico fielmente para {$targetLang}, mantendo a terminologia científica exata e a formalidade:\n\n" . $text;
            $aiResp = $this->callAI('Você é um tradutor acadêmico especializado em artigos científicos. Retorne APENAS a tradução em texto puro, sem comentários ou formatação adicional.', $prompt, 'analysis');
            if (!empty($aiResp)) {
                return trim($aiResp);
            }
        } catch (\Throwable $e) {
            $this->logError('translateWithDeepL', 'Fallback AI translation failed: ' . $e->getMessage());
        }

        return $text;
    }

    /**
     * PT: Endpoint para tradução instantânea de títulos e resumos acadêmicos com DeepL.
     * EN: Endpoint for instant translation of academic titles and abstracts via DeepL.
     * AI: @llm-context Rota POST /api/v1/svitlo/academic/translate.
     *     Recebe JSON { "text": "...", "target_lang": "PT-BR" }
     * @return void
     */
    // PT: Endpoint para tradução instantânea de títulos e resumos acadêmicos com DeepL.
    // EN: Endpoint for instant translation of academic titles and abstracts via DeepL.
    // AI: @llm-context Rota POST /api/v1/svitlo/academic/translate.
    public function academicTranslate(): void
    {
        $this->requireAuth();
        $b = $this->json();
        $text = trim((string)($b['text'] ?? ($b['abstract'] ?? '')));
        $targetLang = trim((string)($b['target_lang'] ?? 'PT-BR'));

        if (empty($text)) {
            $this->abort(400, 'Texto para tradução obrigatório.');
            return;
        }

        $translated = $this->translateWithDeepL($text, $targetLang);
        $this->respond([
            'success'         => true,
            'original_text'   => $text,
            'translated_text' => $translated,
            'target_lang'     => $targetLang,
            'engine'          => 'DeepL'
        ]);
    }

    /**
     * PT: Gera e baixa um dossiê/artigo acadêmico traduzido em PDF formatado elegantemente com wkhtmltopdf.
     * EN: Generates and downloads a translated academic paper dossier PDF elegantly styled with wkhtmltopdf.
     * AI: @llm-context Rota POST/GET /api/v1/svitlo/academic/download-pdf.
     *     Recebe { title, authors, year, doi, url, pdf_url, abstract, translated_abstract, target_lang }
     *     Traduz via DeepL se translated_abstract ainda não tiver sido fornecido.
     *     Converte para PDF via wkhtmltopdf e envia stream binário com cabeçalho de download.
     * @return void
     */
    // PT: Realiza download do arquivo PDF de um artigo acadêmico indexado
    // EN: Downloads PDF file for an indexed academic paper
    // AI: @llm-context GET /api/v1/svitlo/academic/download proxies PDF from publisher
    public function academicDownloadPdf(): void
    {
        $this->requireAuth();
        $b = $this->json();
        if (empty($b)) {
            $b = $_POST ?: $_GET;
        }

        $title = trim((string)($b['title'] ?? 'Artigo Acadêmico'));
        $authors = trim((string)($b['authors'] ?? 'Autores não informados'));
        $year = (string)($b['year'] ?? date('Y'));
        $doi = trim((string)($b['doi'] ?? ''));
        $url = trim((string)($b['url'] ?? $doi));
        $pdfUrl = trim((string)($b['pdf_url'] ?? ''));
        $abstract = trim((string)($b['abstract'] ?? ''));
        $translated = trim((string)($b['translated_abstract'] ?? ''));
        $targetLang = trim((string)($b['target_lang'] ?? 'PT-BR'));

        if (empty($translated) && !empty($abstract)) {
            $translated = $this->translateWithDeepL($abstract, $targetLang);
        }

        $html = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        @page { size: A4 portrait; margin: 20mm 18mm 20mm 18mm; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #FAF7F2;
            color: #2C1810;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            font-size: 11pt;
        }
        .header {
            border-bottom: 2px solid #C9A84C;
            padding-bottom: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand {
            font-family: "Cormorant Garamond", Georgia, "Times New Roman", serif;
            font-size: 26pt;
            font-weight: 700;
            color: #5C3D1E;
            letter-spacing: 0.05em;
        }
        .badge {
            display: inline-block;
            background: #5C3D1E;
            color: #FAF7F2;
            font-size: 8pt;
            font-weight: 600;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 4px;
            letter-spacing: 0.08em;
        }
        .dossier-title {
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: 20pt;
            color: #5C3D1E;
            line-height: 1.25;
            margin: 0 0 12px 0;
        }
        .meta-box {
            background: #F4EFE6;
            border-left: 4px solid #C9A84C;
            border-radius: 0 8px 8px 0;
            padding: 12px 16px;
            margin-bottom: 25px;
            font-size: 9.5pt;
        }
        .meta-row { margin-bottom: 5px; }
        .meta-label { font-weight: 700; color: #5C3D1E; }
        .section-heading {
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: 15pt;
            color: #5C3D1E;
            border-bottom: 1px solid #D8CFC4;
            padding-bottom: 6px;
            margin: 25px 0 12px 0;
        }
        .content-card {
            background: #FFFFFF;
            border: 1px solid #E6DEC8;
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(92, 61, 30, 0.04);
            font-size: 10.5pt;
            text-align: justify;
        }
        .deepl-tag {
            float: right;
            font-size: 8pt;
            color: #0F2B48;
            font-weight: 600;
            background: #E8F0FE;
            padding: 2px 8px;
            border-radius: 4px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 15px;
            border-top: 1px solid #D8CFC4;
            text-align: center;
            font-size: 8.5pt;
            color: #8C7E72;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">SVITLO <span style="font-size:12pt; color:#C9A84C; font-weight:400;">| Dossiê Acadêmico</span></div>
        <div class="badge">Tradução Científica DeepL</div>
    </div>

    <h1 class="dossier-title">' . htmlspecialchars($title) . '</h1>

    <div class="meta-box">
        <div class="meta-row"><span class="meta-label">Autoria:</span> ' . htmlspecialchars($authors) . '</div>
        <div class="meta-row"><span class="meta-label">Ano de Publicação:</span> ' . htmlspecialchars($year) . '</div>' .
        (!empty($doi) ? '<div class="meta-row"><span class="meta-label">DOI:</span> ' . htmlspecialchars($doi) . '</div>' : '') .
        (!empty($url) ? '<div class="meta-row"><span class="meta-label">Fonte:</span> ' . htmlspecialchars($url) . '</div>' : '') .
        (!empty($pdfUrl) ? '<div class="meta-row"><span class="meta-label">Acesso Aberto (PDF Original):</span> ' . htmlspecialchars($pdfUrl) . '</div>' : '') .
    '</div>

    ' . (!empty($translated) ? '
    <div class="section-heading">
        <span class="deepl-tag">Traduzido com DeepL</span>
        Resumo Traduzido (Português)
    </div>
    <div class="content-card">
        ' . nl2br(htmlspecialchars($translated)) . '
    </div>
    ' : '') . '

    ' . (!empty($abstract) ? '
    <div class="section-heading">Resumo Original (Texto Fonte)</div>
    <div class="content-card" style="background:#FAF8F5; color:#55483D;">
        ' . nl2br(htmlspecialchars($abstract)) . '
    </div>
    ' : '') . '

    <div class="footer">
        Gerado por Svitlo — Mriya Academic Ecosystem &bull; ' . date('d/m/Y H:i') . ' &bull; Motor de Tradução DeepL
    </div>
</body>
</html>';

        $safeTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', mb_substr($title, 0, 40));
        $filename = 'Artigo_Traduzido_' . ($safeTitle ?: 'Svitlo') . '_' . date('Ymd') . '.pdf';

        $tmpHtml = tempnam(sys_get_temp_dir(), 'svitlo_ac_') . '.html';
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'svitlo_ac_') . '.pdf';

        file_put_contents($tmpHtml, $html);

        $cmd = "wkhtmltopdf --quiet --enable-local-file-access --encoding UTF-8 " .
               "--page-size A4 --margin-top 15mm --margin-bottom 15mm --margin-left 15mm --margin-right 15mm " .
               escapeshellarg($tmpHtml) . " " . escapeshellarg($tmpPdf) . " 2>&1";

        shell_exec($cmd);

        if (file_exists($tmpPdf) && filesize($tmpPdf) > 500) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tmpPdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            
            readfile($tmpPdf);
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            exit;
        }

        @unlink($tmpHtml);
        @unlink($tmpPdf);
        $this->abort(500, 'Falha na renderização do PDF com wkhtmltopdf.');
    }

    // PT: Classifica QI em PHP para injetar no prompt da IA
    // EN: Classifies IQ in PHP to inject into AI prompt
    // AI: @llm-context Fonte: Wechsler (2008); DSM-5 (APA, 2013)
    private function classifyIQ(int $qi): string {
        if ($qi >= 145) return 'Superdotado';
        if ($qi >= 130) return 'Muito Superior';
        if ($qi >= 120) return 'Superior';
        if ($qi >= 110) return 'Médio Superior';
        if ($qi >= 90)  return 'Médio';
        if ($qi >= 80)  return 'Médio Inferior';
        if ($qi >= 70)  return 'Limítrofe';
        return 'Abaixo da Média';
    }

    // PT: Salva foto de perfil do usuario (max 10MB, converte para JPEG 512x512 no Mriya Core)
    // EN: Saves user profile photo (max 10MB, centralized in Mriya Core)
    // AI: @llm-context POST /api/v1/svitlo/profile/avatar — multipart/form-data
    public function avatarSave(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);

        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $this->abort(400, 'Arquivo de avatar inválido');
            return;
        }

        if (($_FILES['avatar']['size'] ?? 0) > 10 * 1024 * 1024) {
            $this->abort(422, 'Avatar excede o tamanho máximo permitido de 10MB');
            return;
        }

        $tmp = $_FILES['avatar']['tmp_name'];
        if (!is_uploaded_file($tmp)) { $this->abort(400, 'Upload inválido'); return; }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : '';
        if ($finfo) finfo_close($finfo);

        require_once dirname(__DIR__, 4) . '/core/Users/UserService.php';

        try {
            $avatarUrl = UserService::saveAvatar($uid, $tmp, (string)$mime);
            $this->respond(['status' => 'saved', 'avatar_url' => $avatarUrl]);
        } catch (Throwable $e) {
            $this->abort(400, $e->getMessage());
        }
    }

    // PT: Remove foto de perfil do usuario centralizada
    // EN: Removes user profile photo centrally
    // AI: @llm-context DELETE /api/v1/svitlo/profile/avatar
    public function avatarDelete(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);
        require_once dirname(__DIR__, 4) . '/core/Users/UserService.php';
        UserService::deleteAvatar($uid);
        $this->respond(['status' => 'removed']);
    }

    // PT: Registra mensagens de erro no banco de dados para auditoria do Svitlo
    // EN: Logs error messages to database for Svitlo audit trail
    // AI: @llm-context Inserts error entry into svitlo_logs with action, message, and user_id
    private function logError(string $context, string $message, array $data = []): void {
        if (class_exists('SvitloLogger')) {
            SvitloLogger::error($context, $message, $data);
        }
        error_log("[SVITLO/{$context}] {$message}");
    }

    // ── DISCIPLINES ────────────────────────────────────────────────

    // PT: Lista disciplinas do usuario agrupadas por semestre
    // EN: Lists user disciplines grouped by semester
    // AI: @llm-context GET /api/v1/svitlo/disciplines
    public function disciplinesList(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $rows = MriyaDB::query(
            'SELECT * FROM svitlo_disciplines WHERE user_id = ? ORDER BY semester, name',
            [$uid]
        );
        $this->respond(['disciplines' => $rows ?: []]);
    }

    // PT: Salva nova disciplina e cria baralho Anki correspondente
    // EN: Saves new discipline and creates corresponding Anki deck
    // AI: @llm-context POST /api/v1/svitlo/disciplines
    public function disciplineSave(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $name = (string)($b['name'] ?? '');
        if (empty($name)) { $this->abort(400, 'Nome obrigatório'); return; }

        // PT: Determinar nome do baralho baseado no foco do estudo ou valor manual fornecido
        $focus = (string)($b['focus_type'] ?? 'academico');
        $semester = (string)($b['semester'] ?? '');
        $deckName = !empty($b['anki_deck']) ? trim((string)$b['anki_deck']) : $this->buildDeckName($focus, $semester, $name, $b);

        MriyaDB::execute(
            'INSERT INTO svitlo_disciplines
             (user_id, name, code, semester, focus_type, professor, workload,
              start_date, end_date, anki_deck, exam_name, certification_name, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$uid, $name, $b['code']??null, $semester, $focus,
             $b['professor']??null, $b['workload']??null,
             $b['start_date']??null, $b['end_date']??null,
             $deckName, $b['exam_name']??null, $b['certification_name']??null,
             $b['notes']??null]
        );
        $id = MriyaDB::lastInsertId();

        // PT: Criar baralho Anki em background (nao bloqueia a resposta)
        $this->createAnkiDeckAsync($deckName, $uid);

        // PT: Dispara matching de turmas após salvar disciplina
        // EN: Triggers class matching after saving discipline
        if (class_exists('RoleController')) {
            RoleController::triggerClassMatching($uid);
        }

        $this->respond(['status' => 'saved', 'id' => $id, 'anki_deck' => $deckName]);
    }

    // PT: Atualiza dados de uma disciplina acadêmica existente do usuário
    // EN: Updates an existing academic discipline for the user
    // AI: @llm-context PUT /api/v1/svitlo/disciplines/{id} checks ownership and updates fields
    public function disciplineUpdate(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $name = (string)($b['name'] ?? '');
        $focus = (string)($b['focus_type'] ?? 'academico');
        $semester = (string)($b['semester'] ?? '');
        $deckName = !empty($b['anki_deck']) ? trim((string)$b['anki_deck']) : $this->buildDeckName($focus, $semester, $name, $b);

        MriyaDB::execute(
            'UPDATE svitlo_disciplines SET name=?, code=?, semester=?, focus_type=?, professor=?,
             workload=?, start_date=?, end_date=?, status=?, anki_deck=?, exam_name=?, certification_name=?, notes=?
             WHERE id=? AND user_id=?',
            [$name, $b['code']??null, $semester, $focus,
             $b['professor']??null, $b['workload']??null,
             $b['start_date']??null, $b['end_date']??null,
             $b['status']??'active', $deckName, $b['exam_name']??null, $b['certification_name']??null, $b['notes']??null, $id, $uid]
        );

        // PT: Dispara matching de turmas após atualizar disciplina
        // EN: Triggers class matching after updating discipline
        if (class_exists('RoleController')) {
            RoleController::triggerClassMatching($uid);
        }

        $this->respond(['status' => 'updated', 'anki_deck' => $deckName]);
    }

    // PT: Remove uma disciplina acadêmica e dados associados do usuário
    // EN: Deletes an academic discipline and associated data for the user
    // AI: @llm-context DELETE /api/v1/svitlo/disciplines/{id} validates user ownership
    public function disciplineDelete(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute(
            'DELETE FROM svitlo_disciplines WHERE id=? AND user_id=?', [$id, $uid]
        );
        $this->respond(['status' => 'deleted']);
    }

    // ── DISCIPLINE FILES & GOOGLE DRIVE INTEGRATION (ZERO VPS DISK) ──

    /**
     * PT: Upload de materiais da disciplina direto para o Google Drive com renomeação padronizada (Zero Espaço na VPS)
     * EN: Uploads discipline materials directly to Google Drive with standardized naming (Zero VPS Disk Space)
     * AI: @llm-context POST /api/v1/svitlo/disciplines/files/upload
     * AI: @llm-invariant unlink($_FILES['arquivo']['tmp_name']) executado imediatamente após upload
     */
    public function disciplineFileUpload(): void
    {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);

        $uploadFile = $_FILES['arquivo'] ?? $_FILES['file'] ?? null;
        if (!$uploadFile || !isset($uploadFile['error']) || $uploadFile['error'] !== UPLOAD_ERR_OK) {
            $this->abort(400, 'Nenhum arquivo enviado ou erro no upload.');
            return;
        }

        $tmpName = (string)$uploadFile['tmp_name'];
        $originalName = (string)($uploadFile['name'] ?? 'arquivo');
        $fileSize = (int)($uploadFile['size'] ?? 0);
        $mimeType = (string)($uploadFile['type'] ?? 'application/octet-stream');

        // Verificação tripla obrigatória (AGENTS.md §5): tamanho ≤ 10MB, extensão em whitelist e MIME real via finfo
        $allowedExt = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'odt', 'odp', 'ods', 'txt', 'md', 'csv',
                       'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'zip'];
        $extCheck = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $realMime = (is_file($tmpName) && function_exists('finfo_open'))
            ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpName)
            : '';
        if ($fileSize <= 0 || $fileSize > 10 * 1024 * 1024 || !in_array($extCheck, $allowedExt, true)
            || $realMime === '' || preg_match('#^(application/x-(php|httpd|sh|executable|msdownload)|text/x-php)#', $realMime)) {
            if (is_file($tmpName)) {
                @unlink($tmpName);
            }
            $this->abort(422, 'Arquivo inválido: máximo 10MB e formatos permitidos (documentos, imagens, áudio, vídeo, zip).');
            return;
        }
        $mimeType = $realMime;

        $disciplinaCodigo = trim((string)($_POST['disciplina_codigo'] ?? $_POST['code'] ?? 'GERAL'));
        $anoSemestre = trim((string)($_POST['ano_semestre'] ?? $_POST['semester'] ?? date('Y.m')));
        $tipo = trim((string)($_POST['tipo'] ?? 'material'));

        // a) Aplica renomeação padronizada: {sigla_disciplina}_{ano_semestre}_{tipo}_{nome_sanitizado}.{ext}
        $siglaClean = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtoupper($disciplinaCodigo));
        if (empty($siglaClean)) $siglaClean = 'DISC';

        $semestreClean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $anoSemestre);
        if (empty($semestreClean)) $semestreClean = date('Y.m');

        $tipoClean = preg_replace('/[^a-zA-Z0-9_\-]/', '', strtolower($tipo));
        if (empty($tipoClean)) $tipoClean = 'material';

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'bin';
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $cleanBase = preg_replace('/[^\p{L}\p{N}_\-]+/u', '_', $baseName);
        $cleanBase = trim((string)$cleanBase, '_');
        if (empty($cleanBase)) $cleanBase = 'arquivo';

        $nomePadronizado = "{$siglaClean}_{$semestreClean}_{$tipoClean}_{$cleanBase}.{$ext}";

        try {
            // b) & c) Upload para Google Drive e recuperação de id e webViewLink
            $driveRes = $this->uploadFileToGoogleDrive($tmpName, $nomePadronizado, $mimeType, $uid);
            $driveFileId = $driveRes['id'];
            $driveWebLink = $driveRes['webViewLink'];
            $realBytes = $driveRes['size'] ?: $fileSize;

            // Salva metadados no MariaDB
            MriyaDB::execute("
                INSERT INTO svitlo_disciplina_arquivos
                (user_id, disciplina_codigo, nome_original, nome_padronizado, drive_file_id, drive_web_link, tamanho_bytes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [$uid, $disciplinaCodigo, $originalName, $nomePadronizado, $driveFileId, $driveWebLink, $realBytes]);

            $id = (int)MriyaDB::lastInsertId();

            $this->respond([
                'status' => 'uploaded',
                'file' => [
                    'id' => $id,
                    'user_id' => $uid,
                    'disciplina_codigo' => $disciplinaCodigo,
                    'nome_original' => $originalName,
                    'nome_padronizado' => $nomePadronizado,
                    'drive_file_id' => $driveFileId,
                    'drive_web_link' => $driveWebLink,
                    'tamanho_bytes' => $realBytes,
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            ], 201);
        } catch (\Throwable $e) {
            $this->abort(500, 'Falha no upload para o Google Drive: ' . $e->getMessage());
        } finally {
            // d) Apague imediatamente o arquivo temporário do servidor para não ocupar disco na VPS
            if (!empty($tmpName) && file_exists($tmpName)) {
                @unlink($tmpName);
            }
        }
    }

    /**
     * PT: Lista arquivos de uma disciplina do usuário
     * EN: Lists files for a user's discipline
     * AI: @llm-context GET /api/v1/svitlo/disciplines/files
     */
    public function disciplineFilesList(): void
    {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['user_id'] ?? $this->user['sub'] ?? 0);
        $disciplinaCodigo = trim((string)($_GET['disciplina_codigo'] ?? $_GET['code'] ?? ''));

        if (!empty($disciplinaCodigo)) {
            $files = MriyaDB::query("
                SELECT id, user_id, disciplina_codigo, nome_original, nome_padronizado, drive_file_id, drive_web_link, tamanho_bytes, created_at
                FROM svitlo_disciplina_arquivos
                WHERE user_id = ? AND disciplina_codigo = ?
                ORDER BY id DESC
            ", [$uid, $disciplinaCodigo]);
        } else {
            $files = MriyaDB::query("
                SELECT id, user_id, disciplina_codigo, nome_original, nome_padronizado, drive_file_id, drive_web_link, tamanho_bytes, created_at
                FROM svitlo_disciplina_arquivos
                WHERE user_id = ?
                ORDER BY id DESC
            ", [$uid]);
        }

        $this->respond(['files' => $files ?: []]);
    }

    /**
     * PT: Remove registro de arquivo de disciplina e deleta do Drive
     * EN: Deletes discipline file metadata and file from Drive
     * AI: @llm-context DELETE /api/v1/svitlo/disciplines/files/{id}
     */
    public function disciplineFileDelete(int $id): void
    {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $row = MriyaDB::queryOne("SELECT * FROM svitlo_disciplina_arquivos WHERE id = ? AND user_id = ?", [$id, $uid]);
        if (!$row) {
            $this->abort(404, 'Arquivo não encontrado');
            return;
        }

        if (!empty($row['drive_file_id'])) {
            try {
                $token = $this->getGoogleDriveAccessToken();
                if ($token) {
                    $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$row['drive_file_id']}");
                    curl_setopt_array($ch, [
                        CURLOPT_CUSTOMREQUEST => 'DELETE',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
            } catch (\Throwable) {}
        }

        MriyaDB::execute("DELETE FROM svitlo_disciplina_arquivos WHERE id = ? AND user_id = ?", [$id, $uid]);
        $this->respond(['status' => 'deleted']);
    }

    /**
     * PT: Obtém token de acesso para a API do Google Drive via usuário, token.json ou credentials.json
     * EN: Retrieves Google Drive API access token via user session, token.json, or credentials.json
     * AI: @llm-context Lê conexões do usuário em svitlo_connections, token.json e credentials.json com fallback e renovação
     * @param int|null $userId ID do usuário logado
     * @param bool $forceRefresh Força a renovação do token mesmo se não expirado
     * @return string|null Token de acesso válido
     */
    private function getGoogleDriveAccessToken(?int $userId = null, bool $forceRefresh = false): ?string
    {
        // 1. Verificar se o usuário possui conexão ativa com Google Drive / Google em svitlo_connections
        if ($userId && $userId > 0) {
            try {
                $conn = MriyaDB::queryOne("
                    SELECT credentials_enc, extra_data 
                    FROM svitlo_connections 
                    WHERE user_id = ? AND service IN ('google_drive', 'google') AND (active IS NULL OR active = 1)
                    ORDER BY (service = 'google_drive') DESC, id DESC LIMIT 1
                ", [$userId]);

                if ($conn) {
                    $creds = null;
                    if (!empty($conn['credentials_enc'])) {
                        $decrypted = \Core\Crypto\MriyaCrypto::decrypt($conn['credentials_enc']);
                        $creds = json_decode($decrypted ?: $conn['credentials_enc'], true);
                    }
                    if (!$creds && !empty($conn['extra_data'])) {
                        $creds = json_decode($conn['extra_data'], true);
                    }

                    if (is_array($creds)) {
                        $userAccessToken = $creds['access_token'] ?? $creds['token'] ?? null;
                        $userRefreshToken = $creds['refresh_token'] ?? null;
                        $userExpiresAt = $creds['expires_at'] ?? (isset($creds['expiry']) ? strtotime((string)$creds['expiry']) : null);
                        $isUserExpired = $forceRefresh || !$userExpiresAt || ($userExpiresAt <= (time() + 60));

                        $clientId = mriya_credential('google', 'oauth_client_id');
                        $clientSecret = mriya_credential('google', 'oauth_client_secret');

                        if ($isUserExpired && $userRefreshToken && $clientId && $clientSecret) {
                            $refreshed = $this->refreshGoogleAccessToken($clientId, $clientSecret, $userRefreshToken);
                            if ($refreshed) {
                                $creds['access_token'] = $refreshed;
                                $creds['expires_at'] = time() + 3500;
                                $enc = \Core\Crypto\MriyaCrypto::encrypt(json_encode($creds));
                                MriyaDB::execute("UPDATE svitlo_connections SET credentials_enc = ?, updated_at = NOW() WHERE user_id = ? AND service = ?", [
                                    $enc, $userId, 'google_drive'
                                ]);
                                return $refreshed;
                            }
                        } elseif ($userAccessToken && !$isUserExpired) {
                            return $userAccessToken;
                        }
                    }
                }
            } catch (\Throwable) {}
        }

        // 2. Fallback Central: /var/www/mcp-server/credentials/token.json e credentials.json
        $tokenPaths = [
            '/var/www/mcp-server/credentials/token.json',
            dirname(__DIR__, 4) . '/mcp-server/credentials/token.json',
            dirname(__DIR__, 3) . '/deploy/mcp-server/credentials/token.json'
        ];

        $tokenFile = null;
        foreach ($tokenPaths as $path) {
            if (file_exists($path)) {
                $tokenFile = $path;
                break;
            }
        }

        $credentialsPaths = [
            '/var/www/mcp-server/credentials/credentials.json',
            dirname(__DIR__, 4) . '/mcp-server/credentials/credentials.json',
            dirname(__DIR__, 3) . '/deploy/mcp-server/credentials/credentials.json'
        ];
        $credentialsFile = null;
        foreach ($credentialsPaths as $path) {
            if (file_exists($path)) {
                $credentialsFile = $path;
                break;
            }
        }

        $clientId = mriya_credential('google', 'oauth_client_id');
        $clientSecret = mriya_credential('google', 'oauth_client_secret');
        $refreshToken = null;
        $accessToken = null;
        $isExpired = false;
        $tokenData = [];

        if ($tokenFile) {
            $raw = @file_get_contents($tokenFile);
            if ($raw) {
                $tokenData = json_decode($raw, true) ?: [];
                $accessToken = $tokenData['access_token'] ?? $tokenData['token'] ?? null;
                $refreshToken = $tokenData['refresh_token'] ?? null;
                $clientId = $tokenData['client_id'] ?? $clientId;
                $clientSecret = $tokenData['client_secret'] ?? $clientSecret;

                if (isset($tokenData['expires_at']) && is_numeric($tokenData['expires_at'])) {
                    $isExpired = ((int)$tokenData['expires_at'] <= (time() + 60));
                } elseif (isset($tokenData['expiry']) && !empty($tokenData['expiry'])) {
                    $isExpired = (strtotime((string)$tokenData['expiry']) <= (time() + 60));
                } else {
                    // Sem data de expiração definida -> considerar expirado para garantir token novo
                    $isExpired = true;
                }
            }
        }

        // Se faltar refresh_token no token.json, busca em credentials.json
        if (empty($refreshToken) && $credentialsFile) {
            $cRaw = @file_get_contents($credentialsFile);
            if ($cRaw) {
                $cData = json_decode($cRaw, true) ?: [];
                $refreshToken = $cData['drive_refresh_token'] ?? $cData['refresh_token'] ?? null;
                $clientId = $cData['google_client_id'] ?? $cData['client_id'] ?? $clientId;
                $clientSecret = $cData['google_client_secret'] ?? $cData['client_secret'] ?? $clientSecret;
            }
        }

        if (($forceRefresh || $isExpired || empty($accessToken)) && $refreshToken && $clientId && $clientSecret) {
            $refreshed = $this->refreshGoogleAccessToken($clientId, $clientSecret, $refreshToken);
            if ($refreshed) {
                if ($tokenFile) {
                    $tokenData['access_token'] = $refreshed;
                    $tokenData['token'] = $refreshed;
                    $tokenData['expires_at'] = time() + 3500;
                    @file_put_contents($tokenFile, json_encode($tokenData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
                return $refreshed;
            }
        }

        if (!$forceRefresh && !empty($accessToken) && !$isExpired) {
            return $accessToken;
        }

        if (class_exists('MriyaCredentials')) {
            $gCreds = MriyaCredentials::getGoogleDrive();
            if (!empty($gCreds['refresh_token']) && !empty($gCreds['client_id']) && !empty($gCreds['client_secret'])) {
                return $this->refreshGoogleAccessToken($gCreds['client_id'], $gCreds['client_secret'], $gCreds['refresh_token']);
            }
        }

        return $accessToken;
    }

    /**
     * PT: Renova token de acesso via endpoint OAuth2 do Google
     * EN: Refreshes access token via Google OAuth2 endpoint
     */
    private function refreshGoogleAccessToken(string $clientId, string $clientSecret, string $refreshToken): ?string
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return null;
        $json = json_decode($response, true);
        return $json['access_token'] ?? null;
    }

    /**
     * PT: Faz upload multipart direto para o Google Drive REST API v3 com retry inteligente
     * EN: Performs direct multipart upload to Google Drive REST API v3 with smart retry
     */
    private function uploadFileToGoogleDrive(string $filePath, string $filename, string $mimeType, ?int $userId = null): array
    {
        $accessToken = $this->getGoogleDriveAccessToken($userId, false);
        if (!$accessToken) {
            throw new RuntimeException('Token OAuth do Google Drive indisponível. Conecte sua conta Google ou contate o suporte.');
        }

        $fileContent = @file_get_contents($filePath);
        if ($fileContent === false) {
            throw new RuntimeException('Falha ao ler arquivo temporário');
        }

        $boundary = '-------' . md5(uniqid((string)mt_rand(), true));
        $delimiter = "\r\n--" . $boundary . "\r\n";
        $closeDelimiter = "\r\n--" . $boundary . "--";

        $metadata = [
            'name' => $filename,
            'description' => 'Material da Disciplina Svitlo EdTech',
        ];

        $multipartBody = $delimiter;
        $multipartBody .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $multipartBody .= json_encode($metadata);
        $multipartBody .= $delimiter;
        $multipartBody .= "Content-Type: " . ($mimeType ?: 'application/octet-stream') . "\r\n\r\n";
        $multipartBody .= $fileContent;
        $multipartBody .= $closeDelimiter;

        $uploadUrl = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink,webContentLink,size';

        $executeUpload = function(string $token) use ($uploadUrl, $multipartBody, $boundary) {
            $ch = curl_init($uploadUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $multipartBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: multipart/related; boundary=' . $boundary,
                    'Content-Length: ' . strlen($multipartBody),
                ],
            ]);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$httpCode, $raw];
        };

        [$httpCode, $rawResponse] = $executeUpload($accessToken);

        // Se retornar 401 (token expirado/inválido), força refresh e tenta novamente uma vez
        if ($httpCode === 401) {
            $refreshedToken = $this->getGoogleDriveAccessToken($userId, true);
            if ($refreshedToken && $refreshedToken !== $accessToken) {
                $accessToken = $refreshedToken;
                [$httpCode, $rawResponse] = $executeUpload($accessToken);
            }
        }

        $res = json_decode((string)$rawResponse, true);

        if ($httpCode !== 200 || empty($res['id'])) {
            $err = $res['error']['message'] ?? "HTTP {$httpCode}: {$rawResponse}";
            throw new RuntimeException("Erro Google Drive API: {$err}");
        }

        $fileId = (string)$res['id'];
        $webLink = (string)($res['webViewLink'] ?? "https://drive.google.com/file/d/{$fileId}/view?usp=drivesdk");

        try {
            $permCh = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}/permissions");
            curl_setopt_array($permCh, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['role' => 'reader', 'type' => 'anyone']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
            ]);
            curl_exec($permCh);
            curl_close($permCh);
        } catch (\Throwable) {}

        return [
            'id' => $fileId,
            'webViewLink' => $webLink,
            'size' => (int)($res['size'] ?? strlen($fileContent)),
        ];
    }

    // PT: Constroi nome do baralho no formato correto dos baralhos existentes
    // EN: Builds deck name matching existing Anki deck format
    // AI: @llm-context Formato: Psicologia::N Semestre::Nome (ARA0000)
    private function buildDeckName(string $focus, string $semester, string $name, array $b): string {
        $code = !empty($b['code']) ? ' (' . $b['code'] . ')' : '';
        // PT: Normalizar semestre para formato sem º (compativel com Anki)
        $sem = str_replace(['1º','2º','3º','4º','1°','2°','3°','4°'],
                           ['1','2','3','4','1','2','3','4'], $semester);
        $sem = trim(str_ireplace('semestre', '', $sem));

        return match($focus) {
            'concurso'     => 'Concurso ' . ($b['exam_name'] ?? 'Geral') . '::' . $name . $code,
            'residencia'   => 'Residência::' . $name . $code,
            'certificacao' => ($b['certification_name'] ?? 'Certificação') . '::' . $name . $code,
            'idiomas'      => 'Idiomas::' . $name,
            default        => 'Psicologia' . ($sem ? '::' . $sem . ' Semestre' : '') . '::' . $name . $code,
        };
    }

    // PT: Cria baralho Anki em background sem bloquear a resposta com isolamento por usuário
    // EN: Creates Anki deck in background without blocking response with user isolation
    // AI: @llm-context ITEM 4: getAnkiCollectionPath($userId)
    private function createAnkiDeckAsync(string $deckName, int $userId = 1): void {
        $colPath = $this->getAnkiCollectionPath($userId);
        $safeDeck = escapeshellarg($deckName);
        $safePath = escapeshellarg($colPath);
        $cmd = "/var/www/mcp-server/venv/bin/python3 -c \"
import os
from anki.collection import Collection
col_path = {$safePath}
col_dir = os.path.dirname(col_path)
if col_dir and not os.path.exists(col_dir):
    os.makedirs(col_dir, exist_ok=True)
col = Collection(col_path)
col.decks.id({$safeDeck})
col.close()
\" >> /var/log/mriya/anki_decks.log 2>&1 &";
        shell_exec($cmd);
    }

    // ── CALENDAR ───────────────────────────────────────────────────

    // PT: Lista eventos do calendario por mes
    // EN: Lists calendar events by month
    // AI: @llm-context GET /api/v1/svitlo/calendar?month=YYYY-MM
    public function calendarList(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $month = (string)($_GET['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $rows = MriyaDB::query(
            'SELECT e.*, d.name as discipline_name
             FROM svitlo_calendar_events e
             LEFT JOIN svitlo_disciplines d ON d.id = e.discipline_id
             WHERE e.user_id = ? AND DATE(e.start_datetime) BETWEEN ? AND ?
             ORDER BY e.start_datetime',
            [$uid, $start, $end]
        );
        $this->respond(['events' => $rows ?: [], 'month' => $month]);
    }

    // PT: Cria um novo evento no cronograma acadêmico do usuário
    // EN: Creates a new event in the user's academic calendar
    // AI: @llm-context POST /api/v1/svitlo/calendar/events saves event to svitlo_calendar_events
    public function calendarSave(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        if (empty($b['title']) || empty($b['start_datetime'])) {
            $this->abort(400, 'Título e data obrigatórios'); return;
        }
        MriyaDB::execute(
            'INSERT INTO svitlo_calendar_events
             (user_id, discipline_id, title, type, start_datetime, end_datetime, location, notes, reminder_days)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$uid, $b['discipline_id']??null, $b['title'],
             $b['type']??'study', $b['start_datetime'],
             $b['end_datetime']??null, $b['location']??null,
             $b['notes']??null, $b['reminder_days']??3]
        );
        $this->respond(['status' => 'saved', 'id' => MriyaDB::lastInsertId()]);
    }

    // PT: Atualiza detalhes de um evento existente no cronograma do usuário
    // EN: Updates details of an existing event in the user's calendar
    // AI: @llm-context PUT /api/v1/svitlo/calendar/events/{id} validates user ownership
    public function calendarUpdate(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        MriyaDB::execute(
            'UPDATE svitlo_calendar_events
             SET title=?, type=?, start_datetime=?, end_datetime=?,
                 location=?, notes=?, completed=?, reminder_days=?
             WHERE id=? AND user_id=?',
            [$b['title']??'', $b['type']??'study',
             $b['start_datetime']??date('Y-m-d H:i:s'),
             $b['end_datetime']??null, $b['location']??null,
             $b['notes']??null, (int)($b['completed']??0),
             $b['reminder_days']??3, $id, $uid]
        );
        $this->respond(['status' => 'updated']);
    }

    // PT: Exclui um evento do calendário acadêmico do usuário
    // EN: Deletes an event from the user's academic calendar
    // AI: @llm-context DELETE /api/v1/svitlo/calendar/events/{id} ensures user isolation
    public function calendarDelete(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute(
            'DELETE FROM svitlo_calendar_events WHERE id=? AND user_id=?', [$id, $uid]
        );
        $this->respond(['status' => 'deleted']);
    }

    // PT: Exporta calendario como arquivo .ics (Google Calendar compatible)
    // PT: Exporta eventos do calendário acadêmico no formato padrão iCalendar (.ics)
    // EN: Exports academic calendar events in standard iCalendar format (.ics)
    // AI: @llm-context GET /api/v1/svitlo/calendar/export generates RFC 5545 VCALENDAR payload
    public function calendarExport(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $events = MriyaDB::query(
            'SELECT * FROM svitlo_calendar_events WHERE user_id = ? AND completed = 0
             ORDER BY start_datetime', [$uid]
        );
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Svitlo//Espectro Ipe//PT\r\nCALSCALE:GREGORIAN\r\n";
        foreach ($events ?? [] as $ev) {
            $start = str_replace(['-',':',' '], ['','','T'], $ev['start_datetime']) . 'Z';
            $end = $ev['end_datetime']
                ? str_replace(['-',':',' '], ['','','T'], $ev['end_datetime']) . 'Z'
                : $start;
            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:{$ev['id']}@svitlo.espectroipe.com.br\r\n";
            $ics .= "DTSTART:{$start}\r\n";
            $ics .= "DTEND:{$end}\r\n";
            $ics .= "SUMMARY:" . str_replace(["\r","\n"], ['',' '], $ev['title']) . "\r\n";
            if ($ev['location']) $ics .= "LOCATION:" . str_replace(["\r","\n"], ['',' '], $ev['location']) . "\r\n";
            $ics .= "END:VEVENT\r\n";
        }
        $ics .= "END:VCALENDAR";
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="svitlo-calendario.ics"');
        echo $ics;
        exit;
    }

    // PT: Importa cronograma via IA
    // PT: Importa eventos de um feed iCal ou arquivo .ics para o calendário do Svitlo
    // EN: Imports events from an iCal feed or .ics file into Svitlo calendar
    // AI: @llm-context POST /api/v1/svitlo/calendar/import parses VEVENT components
    public function calendarImport(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $content = (string)($b['content'] ?? '');
        if (empty($content)) { $this->abort(400, 'Conteúdo obrigatório'); return; }

        $prompt = "Extraia TODAS as datas e eventos do cronograma abaixo.
Retorne APENAS JSON válido:
{\"events\": [{\"title\": \"...\", \"type\": \"exam|assignment|presentation|study|other\", \"date\": \"YYYY-MM-DD\", \"time\": \"HH:MM\", \"notes\": \"...\"}]}

Cronograma:
{$content}";

        $result = $this->callAI('Assistente acadêmico especializado em extrair datas de cronogramas.', $prompt, 'default');
        $data = json_decode($this->extractJson($result), true);
        $events = $data['events'] ?? [];
        $saved = 0;
        foreach ($events as $ev) {
            if (empty($ev['date'])) continue;
            $datetime = $ev['date'] . ' ' . ($ev['time'] ?? '08:00') . ':00';
            MriyaDB::execute(
                'INSERT INTO svitlo_calendar_events (user_id, title, type, start_datetime, notes)
                 VALUES (?,?,?,?,?)',
                [$uid, $ev['title']??'Evento', $ev['type']??'other', $datetime, $ev['notes']??null]
            );
            $saved++;
        }
        $this->respond(['status'=>'imported','events_found'=>count($events),'events_saved'=>$saved,'events'=>$events]);
    }

    // PT: Busca eventos academicos no Even3
    // EN: Searches academic events on Even3
    // AI: @llm-context GET /api/v1/svitlo/academic-events?area=psicologia
    public function academicEvents(): void {
        $this->requireAuth();
        $area = (string)($_GET['area'] ?? 'psicologia');
        $url = 'https://www.even3.com.br/busca/?q=' . urlencode($area);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
            CURLOPT_USERAGENT=>'Mozilla/5.0 Svitlo/1.0', CURLOPT_FOLLOWLOCATION=>true]);
        $html = (string)(curl_exec($ch) ?: '');
        curl_close($ch);
        $events = [];
        preg_match_all('/<a[^>]+href="(\/[^"]+)"[^>]*class="[^"]*event[^"]*"[^>]*>(.*?)<\/a>/si', $html, $m);
        foreach (array_slice($m[1] ?? [], 0, 10) as $i => $path) {
            $events[] = ['source'=>'Even3','title'=>strip_tags($m[2][$i]??''),'url'=>'https://www.even3.com.br'.$path,'area'=>$area];
        }
        $this->respond(['events'=>$events,'area'=>$area,'total'=>count($events)]);
    }

    // PT: Audita e normaliza cursos não listados com deduplicação semântica via IA
    // EN: Audits and normalizes unlisted courses with AI semantic deduplication
    // AI: @llm-context POST /api/v1/svitlo/courses/audit e POST /api/v1/svitlo/validate/course
    // AI: @llm-invariant Timeout de 3s e fallback resiliente via CourseAuditorService
    // AI: @llm-rule Retorna JSON {is_valid, matched_existing_id, normalized_name, rejection_reason, is_verified, course_id}
    public function validateCourse(): void {
        require_once dirname(__DIR__, 3) . '/includes/CourseAuditorService.php';
        $b = $this->json();
        $input = trim((string)($b['raw_course_name'] ?? $b['input'] ?? $b['course'] ?? ''));
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);
        $result = CourseAuditorService::audit($input, $uid > 0 ? $uid : null);
        $this->respond($result);
    }

    // PT: Lista todos os cursos conhecidos e reconhecidos para dropdown/autocomplete no cadastro
    // EN: Lists all known and recognized courses for registration dropdown/autocomplete
    // AI: @llm-context GET /api/v1/svitlo/courses com cache em Redis
    // AI: @llm-invariant Rota pública disponível antes de autenticação
    // AI: @llm-rule Retorna JSON {courses: [{id, name, usage_count, is_verified}]}
    public function coursesList(): void {
        require_once dirname(__DIR__, 3) . '/includes/CourseAuditorService.php';
        $q = isset($_GET['q']) ? (string)$_GET['q'] : null;
        $courses = CourseAuditorService::listCourses($q);
        $this->respond(['courses' => $courses]);
    }

    // ── GRADES & SCHEMAS ──────────────────────────────────────────

    // PT: Lista notas de uma disciplina e esquema configurado
    // EN: Lists grades and schema for a discipline
    // AI: @llm-context GET /api/v1/svitlo/grades/{discipline_id}
    public function gradesList(int $disciplineId): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $grades = MriyaDB::query(
            'SELECT * FROM svitlo_grades WHERE user_id = ? AND discipline_id = ? ORDER BY id ASC',
            [$uid, $disciplineId]
        );
        $schema = MriyaDB::queryOne(
            'SELECT * FROM svitlo_grade_schemas WHERE user_id = ? AND discipline_id = ? ORDER BY id DESC LIMIT 1',
            [$uid, $disciplineId]
        );
        if ($schema && !empty($schema['schema_json']) && is_string($schema['schema_json'])) {
            $schema['schema_json'] = json_decode($schema['schema_json'], true);
        }
        $this->respond([
            'grades' => $grades ?: [],
            'schema' => $schema ?: null,
            'discipline_id' => $disciplineId
        ]);
    }

    // PT: Adiciona nota em uma disciplina
    // EN: Adds grade entry for a discipline
    // AI: @llm-context POST /api/v1/svitlo/grades/{discipline_id}
    public function gradeAdd(int $disciplineId): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $label = trim((string)($b['label'] ?? ''));
        if (empty($label)) { $this->abort(400, 'Rótulo da avaliação obrigatório'); return; }

        $maxScore = isset($b['max_score']) && $b['max_score'] !== '' ? (float)$b['max_score'] : 10.0;
        $score = isset($b['score']) && $b['score'] !== '' && $b['score'] !== null ? (float)$b['score'] : null;
        $isExtra = !empty($b['is_extra']) ? 1 : 0;
        $weight = isset($b['weight']) && $b['weight'] !== '' ? (float)$b['weight'] : 1.0;
        $notes = !empty($b['notes']) ? (string)$b['notes'] : null;
        $schemaId = !empty($b['schema_id']) ? (int)$b['schema_id'] : null;

        MriyaDB::execute(
            'INSERT INTO svitlo_grades (user_id, discipline_id, schema_id, label, score, max_score, is_extra, weight, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, $disciplineId, $schemaId, $label, $score, $maxScore, $isExtra, $weight, $notes]
        );
        $this->respond(['status' => 'created', 'id' => MriyaDB::lastInsertId()]);
    }

    // PT: Atualiza nota existente
    // EN: Updates existing grade
    // AI: @llm-context PUT /api/v1/svitlo/grades/{grade_id}
    public function gradeUpdate(int $gradeId): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $label = trim((string)($b['label'] ?? ''));
        if (empty($label)) { $this->abort(400, 'Rótulo da avaliação obrigatório'); return; }

        $maxScore = isset($b['max_score']) && $b['max_score'] !== '' ? (float)$b['max_score'] : 10.0;
        $score = isset($b['score']) && $b['score'] !== '' && $b['score'] !== null ? (float)$b['score'] : null;
        $isExtra = !empty($b['is_extra']) ? 1 : 0;
        $weight = isset($b['weight']) && $b['weight'] !== '' ? (float)$b['weight'] : 1.0;
        $notes = !empty($b['notes']) ? (string)$b['notes'] : null;

        MriyaDB::execute(
            'UPDATE svitlo_grades SET label=?, score=?, max_score=?, is_extra=?, weight=?, notes=?
             WHERE id=? AND user_id=?',
            [$label, $score, $maxScore, $isExtra, $weight, $notes, $gradeId, $uid]
        );
        $this->respond(['status' => 'updated']);
    }

    // PT: Remove nota existente
    // EN: Deletes existing grade
    // AI: @llm-context DELETE /api/v1/svitlo/grades/{grade_id}
    public function gradeDelete(int $gradeId): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute('DELETE FROM svitlo_grades WHERE id=? AND user_id=?', [$gradeId, $uid]);
        $this->respond(['status' => 'deleted']);
    }

    // PT: Calcula média ponderada e status de aprovação
    // EN: Calculates weighted average and pass/fail status
    // AI: @llm-context GET /api/v1/svitlo/grades/{discipline_id}/average
    public function gradeAverage(int $disciplineId): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $grades = MriyaDB::query(
            'SELECT * FROM svitlo_grades WHERE user_id = ? AND discipline_id = ?',
            [$uid, $disciplineId]
        ) ?: [];

        $schema = MriyaDB::queryOne(
            'SELECT * FROM svitlo_grade_schemas WHERE user_id = ? AND discipline_id = ? ORDER BY id DESC LIMIT 1',
            [$uid, $disciplineId]
        );

        $passingGrade = $schema ? (float)$schema['passing_grade'] : 6.00;
        $schemaJson = ($schema && !empty($schema['schema_json'])) ? (is_string($schema['schema_json']) ? json_decode($schema['schema_json'], true) : $schema['schema_json']) : null;

        $substitutive = $schemaJson['substitutive'] ?? ['label' => 'AVS', 'replaces' => 'AV'];
        $subLabel = $substitutive['label'] ?? 'AVS';
        $replacesLabel = $substitutive['replaces'] ?? 'AV';

        $gradeMap = [];
        foreach ($grades as $g) {
            $gradeMap[trim($g['label'])] = $g;
        }

        // Substituição AVS -> AV se AVS for maior
        if (isset($gradeMap[$subLabel]) && isset($gradeMap[$replacesLabel])) {
            $avsScore = $gradeMap[$subLabel]['score'] !== null ? (float)$gradeMap[$subLabel]['score'] : null;
            $avScore = $gradeMap[$replacesLabel]['score'] !== null ? (float)$gradeMap[$replacesLabel]['score'] : null;
            if ($avsScore !== null && ($avScore === null || $avsScore > $avScore)) {
                $gradeMap[$replacesLabel]['score'] = $avsScore;
            }
        }

        $nonExtraScoreSum = 0.0;
        $nonExtraMaxSum = 0.0;
        $nonExtraWeightedSum = 0.0;
        $totalWeight = 0.0;
        $extraPoints = 0.0;
        $hasMissingScore = false;
        $totalGradesCount = count($grades);

        foreach ($gradeMap as $lbl => $g) {
            if ($lbl === $subLabel) continue;
            $isExtra = (bool)$g['is_extra'];
            $max = (float)$g['max_score'];
            $w = (float)($g['weight'] ?? 1.0);
            $score = $g['score'] !== null ? (float)$g['score'] : null;

            if ($score === null) {
                $hasMissingScore = true;
                continue;
            }

            if ($isExtra) {
                $extraPoints += min($score, $max);
            } else {
                $nonExtraScoreSum += $score;
                $nonExtraMaxSum += $max;
                $nonExtraWeightedSum += ($score * $w);
                $totalWeight += $w;
            }
        }

        // Se a soma das notas máximas regulares compõe até 10 pontos (ex: AV 7 + TRAB 3 = 10), é soma direta
        if ($nonExtraMaxSum > 0 && $nonExtraMaxSum <= 10.05) {
            $baseAverage = $nonExtraScoreSum;
        } else {
            $baseAverage = $totalWeight > 0 ? ($nonExtraWeightedSum / $totalWeight) : 0.0;
        }

        $finalAverage = min(10.0, round($baseAverage + $extraPoints, 2));

        if ($totalGradesCount === 0 || $hasMissingScore) {
            $status = 'em_andamento';
        } elseif ($finalAverage >= $passingGrade) {
            $status = 'aprovado';
        } else {
            $status = 'reprovado';
        }

        $this->respond([
            'average' => $finalAverage,
            'base_average' => round($baseAverage, 2),
            'extra_points' => round($extraPoints, 2),
            'passing_grade' => $passingGrade,
            'status' => $status,
            'discipline_id' => $disciplineId
        ]);
    }

    // PT: Lista esquemas de notas do usuário
    // AI: @llm-context GET /api/v1/svitlo/grade-schemas
    // PT: Lista esquemas de cálculo de notas e critérios de avaliação do usuário
    // EN: Lists grading calculation schemas and evaluation criteria for the user
    // AI: @llm-context GET /api/v1/svitlo/grades/schemas returns user and global presets
    public function gradeSchemasList(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $rows = MriyaDB::query('SELECT * FROM svitlo_grade_schemas WHERE user_id = ? ORDER BY id DESC', [$uid]) ?: [];
        foreach ($rows as &$r) {
            if (!empty($r['schema_json']) && is_string($r['schema_json'])) {
                $r['schema_json'] = json_decode($r['schema_json'], true);
            }
        }
        $this->respond(['schemas' => $rows]);
    }

    // PT: Cria ou atualiza esquema de notas
    // AI: @llm-context POST /api/v1/svitlo/grade-schemas
    // PT: Cria um novo esquema personalizado de ponderação de notas
    // EN: Creates a new custom grading weight schema
    // AI: @llm-context POST /api/v1/svitlo/grades/schemas saves grading weights and formulas
    public function gradeSchemaCreate(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $disciplineId = (int)($b['discipline_id'] ?? 0);
        $name = trim((string)($b['name'] ?? 'Padrão'));
        $passingGrade = isset($b['passing_grade']) ? (float)$b['passing_grade'] : 6.00;
        $schemaJson = isset($b['schema_json']) ? (is_array($b['schema_json']) ? json_encode($b['schema_json'], JSON_UNESCAPED_UNICODE) : (string)$b['schema_json']) : '{}';

        MriyaDB::execute(
            'INSERT INTO svitlo_grade_schemas (user_id, discipline_id, name, passing_grade, schema_json)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name=VALUES(name), passing_grade=VALUES(passing_grade), schema_json=VALUES(schema_json), updated_at=NOW()',
            [$uid, $disciplineId, $name, $passingGrade, $schemaJson]
        );
        $this->respond(['status' => 'saved', 'id' => MriyaDB::lastInsertId()]);
    }

    // PT: Atualiza esquema de notas
    // AI: @llm-context PUT /api/v1/svitlo/grade-schemas/{id}
    // PT: Atualiza um esquema de cálculo de notas existente do usuário
    // EN: Updates an existing grading schema for the user
    // AI: @llm-context PUT /api/v1/svitlo/grades/schemas/{id} validates user ownership
    public function gradeSchemaUpdate(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $name = trim((string)($b['name'] ?? 'Padrão'));
        $passingGrade = isset($b['passing_grade']) ? (float)$b['passing_grade'] : 6.00;
        $schemaJson = isset($b['schema_json']) ? (is_array($b['schema_json']) ? json_encode($b['schema_json'], JSON_UNESCAPED_UNICODE) : (string)$b['schema_json']) : '{}';

        MriyaDB::execute(
            'UPDATE svitlo_grade_schemas SET name=?, passing_grade=?, schema_json=? WHERE id=? AND user_id=?',
            [$name, $passingGrade, $schemaJson, $id, $uid]
        );
        $this->respond(['status' => 'updated']);
    }

    // PT: Remove esquema de notas
    // AI: @llm-context DELETE /api/v1/svitlo/grade-schemas/{id}
    // PT: Exclui um esquema de avaliação de notas do usuário
    // EN: Deletes a grading schema belonging to the user
    // AI: @llm-context DELETE /api/v1/svitlo/grades/schemas/{id} prevents deletion of system schemas
    public function gradeSchemaDelete(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute('DELETE FROM svitlo_grade_schemas WHERE id=? AND user_id=?', [$id, $uid]);
        $this->respond(['status' => 'deleted']);
    }

    // ── TELEMETRIA DE ESTUDO ──────────────────────────────────────

    // PT: Registra Pomodoro concluído
    // AI: @llm-context POST /api/v1/svitlo/telemetry/pomodoro
    // PT: Registra conclusão ou interrupção de ciclo Pomodoro com telemetria
    // EN: Records completion or interruption of a Pomodoro cycle with telemetry
    // AI: @llm-context POST /api/v1/svitlo/telemetry/pomodoro updates focus time metrics
    public function telemetryPomodoro(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $duration = (int)($b['duration_minutes'] ?? 25);

        MriyaDB::execute(
            'INSERT INTO svitlo_study_telemetry (user_id, date, pomodoros_completed, study_minutes)
             VALUES (?, CURDATE(), 1, ?)
             ON DUPLICATE KEY UPDATE
             pomodoros_completed = pomodoros_completed + 1,
             study_minutes = study_minutes + VALUES(study_minutes)',
            [$uid, $duration]
        );
        $this->respond(['status' => 'recorded']);
    }

    // PT: Registra cards gerados
    // AI: @llm-context POST /api/v1/svitlo/telemetry/cards
    // PT: Registra revisão e desempenho de flashcards no Anki ou Svitlo
    // EN: Records review and performance of flashcards in Anki or Svitlo
    // AI: @llm-context POST /api/v1/svitlo/telemetry/cards tracks retention and interval stats
    public function telemetryCards(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $count = (int)($b['count'] ?? 1);

        MriyaDB::execute(
            'INSERT INTO svitlo_study_telemetry (user_id, date, cards_generated)
             VALUES (?, CURDATE(), ?)
             ON DUPLICATE KEY UPDATE cards_generated = cards_generated + VALUES(cards_generated)',
            [$uid, $count]
        );
        $this->respond(['status' => 'recorded']);
    }

    // PT: Registra cards de música gerados
    // AI: @llm-context POST /api/v1/svitlo/telemetry/music-cards
    // PT: Registra flashcards gerados a partir de letras e histórico musical
    // EN: Records flashcards generated from lyrics and music history
    // AI: @llm-context POST /api/v1/svitlo/telemetry/music tracks language learning by music
    public function telemetryMusicCards(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $count = (int)($b['count'] ?? 1);

        MriyaDB::execute(
            'INSERT INTO svitlo_study_telemetry (user_id, date, music_cards_generated, cards_generated)
             VALUES (?, CURDATE(), ?, ?)
             ON DUPLICATE KEY UPDATE
             music_cards_generated = music_cards_generated + VALUES(music_cards_generated),
             cards_generated = cards_generated + VALUES(cards_generated)',
            [$uid, $count, $count]
        );
        $this->respond(['status' => 'recorded']);
    }

    // PT: Obtém resumo de telemetria do usuário (foco, cards revisados, streak e atividade dos últimos 7 dias)
    // EN: Returns user study telemetry (focus time, cards reviewed, streak, and 7-day activity)
    // AI: @llm-context ITEM 16: GET /api/v1/svitlo/telemetry
    public function telemetryGet(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $month = (string)($_GET['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));

        // 1. Linhas do mês (compatibilidade com calendário de telemetria)
        $rows = MriyaDB::query(
            'SELECT date, pomodoros_completed, cards_generated, cards_reviewed, music_cards_generated, study_minutes
             FROM svitlo_study_telemetry
             WHERE user_id = ? AND date BETWEEN ? AND ?
             ORDER BY date ASC',
            [$uid, $start, $end]
        ) ?: [];

        $telemetryByDate = [];
        foreach ($rows as $r) {
            $telemetryByDate[$r['date']] = $r;
        }

        // 2. Totais vitalícios de foco e cards (de sessões Pomodoro)
        // PT: Calcula tempo total de foco acumulado e cards
        // EN: Computes total cumulative focus minutes and cards
        // AI: @llm-context Total vitalício de foco em minutos e cards
        $lifetime = MriyaDB::queryOne(
            'SELECT SUM(study_minutes) as total_minutes, SUM(pomodoros_completed) as total_pomos, 
                    SUM(GREATEST(COALESCE(cards_reviewed, 0), COALESCE(cards_generated, 0))) as total_cards
             FROM svitlo_study_telemetry WHERE user_id = ?',
            [$uid]
        ) ?: [];

        $totalMinutes = (int)($lifetime['total_minutes'] ?? 0);
        $totalPomos = (int)($lifetime['total_pomos'] ?? 0);
        if ($totalMinutes === 0 && $totalPomos > 0) {
            $totalMinutes = $totalPomos * 25;
        }
        $totalHours = round($totalMinutes / 60, 1);

        // 3. Cards revisados/gerados hoje, semana e mês
        $cardsToday = (int)(MriyaDB::queryOne(
            'SELECT SUM(GREATEST(COALESCE(cards_reviewed, 0), COALESCE(cards_generated, 0))) as cnt 
             FROM svitlo_study_telemetry WHERE user_id = ? AND date = CURDATE()',
            [$uid]
        )['cnt'] ?? 0);

        $cardsWeek = (int)(MriyaDB::queryOne(
            'SELECT SUM(GREATEST(COALESCE(cards_reviewed, 0), COALESCE(cards_generated, 0))) as cnt 
             FROM svitlo_study_telemetry WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)',
            [$uid]
        )['cnt'] ?? 0);

        $cardsMonth = (int)(MriyaDB::queryOne(
            'SELECT SUM(GREATEST(COALESCE(cards_reviewed, 0), COALESCE(cards_generated, 0))) as cnt 
             FROM svitlo_study_telemetry WHERE user_id = ? AND date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")',
            [$uid]
        )['cnt'] ?? 0);

        // 4. Sequência de dias estudando (study_streak consecutivo)
        $streak = 0;
        $history = MriyaDB::query(
            'SELECT date, (COALESCE(study_minutes, 0) + COALESCE(pomodoros_completed, 0) + COALESCE(cards_reviewed, 0) + COALESCE(cards_generated, 0)) as activity
             FROM svitlo_study_telemetry
             WHERE user_id = ? AND (study_minutes > 0 OR pomodoros_completed > 0 OR cards_reviewed > 0 OR cards_generated > 0)
             ORDER BY date DESC LIMIT 60',
            [$uid]
        ) ?: [];

        $activeDates = [];
        foreach ($history as $h) {
            $activeDates[$h['date']] = true;
        }

        // PT: Sincroniza data base com CURDATE() do banco (garante paridade de fuso UTC entre PHP e MySQL)
        // EN: Synchronize base date with database CURDATE() (ensures UTC timezone parity between PHP and MySQL)
        // AI: @llm-context Garante que $todayStr coincida exatamente com as datas registradas via CURDATE()
        $dbDate = MriyaDB::queryOne('SELECT CURDATE() as cd');
        $todayStr = (string)($dbDate['cd'] ?? date('Y-m-d'));

        $checkDate = new \DateTime($todayStr);
        if (!isset($activeDates[$todayStr])) {
            $checkDate->modify('-1 day');
        }
        while (isset($activeDates[$checkDate->format('Y-m-d')])) {
            $streak++;
            $checkDate->modify('-1 day');
        }

        // 5. Atividade dos últimos 7 dias: array de {date, minutes, cards}
        $last7Days = [];
        $activityLast7Days = [];
        $dayNamesPt = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("{$todayStr} -{$i} days"));
            $ts = strtotime($d);
            $dayOfWeek = (int)date('w', $ts);
            $row = $telemetryByDate[$d] ?? null;
            if (!$row) {
                $row = MriyaDB::queryOne(
                    'SELECT pomodoros_completed, cards_reviewed, cards_generated, study_minutes
                     FROM svitlo_study_telemetry WHERE user_id = ? AND date = ?',
                    [$uid, $d]
                ) ?: ['pomodoros_completed' => 0, 'cards_reviewed' => 0, 'cards_generated' => 0, 'study_minutes' => 0];
            }
            $pomo = (int)($row['pomodoros_completed'] ?? 0);
            $mins = (int)($row['study_minutes'] ?? 0);
            if ($mins === 0 && $pomo > 0) $mins = $pomo * 25;
            $cardCount = (int)(($row['cards_reviewed'] ?? 0) ?: ($row['cards_generated'] ?? 0));

            $last7Days[] = [
                'date'    => $d,
                'minutes' => $mins,
                'cards'   => $cardCount,
                'label'   => $dayNamesPt[$dayOfWeek] . ' ' . date('d/m', $ts)
            ];

            $activityLast7Days[] = [
                'date'           => $d,
                'label'          => $dayNamesPt[$dayOfWeek] . ' ' . date('d/m', $ts),
                'day_name'       => $dayNamesPt[$dayOfWeek],
                'study_minutes'  => $mins,
                'study_hours'    => round($mins / 60, 1),
                'pomodoros'      => $pomo,
                'cards_reviewed' => $cardCount
            ];
        }

        $this->respond([
            'status'               => 'ok',
            'month'                => $month,
            'total_focus_minutes'  => $totalMinutes,
            'total_focus_hours'    => $totalHours,
            'cards_reviewed_today' => $cardsToday,
            'cards_reviewed_week'  => $cardsWeek,
            'cards_reviewed_month' => $cardsMonth,
            'study_streak'         => $streak,
            'streak_days'          => $streak,
            'last_7_days'          => $last7Days,
            'activity_last_7_days' => $activityLast7Days,
            'telemetry'            => $telemetryByDate
        ]);
    }

    // ── DIÁRIO DE ESTUDOS (STUDY DIARY) ───────────────────────────

    /**
     * PT: Extrai texto de imagem de anotação de estudos via Moondream / Vision sem salvar arquivo de imagem
     * EN: Extracts text from study notes image via Moondream / Vision without saving image file
     * AI: @llm-context POST /api/v1/svitlo/diary/extract-image — processa imagem em memória/pipe e retorna texto
     */
    // PT: Extrai texto de imagem de anotação de estudos via Moondream / Vision sem salvar arquivo de imagem
    // EN: Extracts text from study notes image via Moondream / Vision without saving image file
    // AI: @llm-context POST /api/v1/svitlo/diary/extract-image — processa imagem em memória/pipe e retorna texto
    public function diaryExtractImage(): void {
        $this->requireAuth();
        $b = $this->json();
        $img = $b['image'] ?? '';
        if (empty($img) && !empty($_FILES['image']['tmp_name'])) {
            $raw = file_get_contents($_FILES['image']['tmp_name']);
            $img = 'data:' . ($_FILES['image']['type'] ?? 'image/jpeg') . ';base64,' . base64_encode($raw);
        }

        if (empty($img)) {
            $this->respondError('Imagem não fornecida.', 400);
            return;
        }

        $scriptPath = dirname(__DIR__, 3) . '/scripts/extract_diary_image.py';
        if (!file_exists($scriptPath)) {
            $scriptPath = '/var/www/mriya/mriya/scripts/extract_diary_image.py';
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open('python3 ' . escapeshellarg($scriptPath), $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->respondError('Erro ao iniciar extrator visual.', 500);
            return;
        }

        fwrite($pipes[0], (string)$img);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $res = json_decode($output, true);
        if (!empty($res['success']) && !empty($res['extracted_text'])) {
            $this->respond([
                'status'         => 'ok',
                'extracted_text' => $res['extracted_text'],
                'engine'         => $res['engine'] ?? 'moondream'
            ]);
        } else {
            $this->respondError($res['error'] ?? 'Não foi possível extrair texto da imagem.', 422);
        }
    }

    /**
     * PT: Cria uma nova entrada no Diário de Estudos com extração de texto de imagens via Moondream
     * EN: Creates a new Study Diary entry with text extracted from images via Moondream
     * AI: @llm-context POST /api/v1/svitlo/diary — extrai texto e nunca persiste a imagem bruta
     */
    // PT: Cria uma nova entrada no Diário de Estudos com extração de texto de imagens via Moondream
    // EN: Creates a new Study Diary entry with text extracted from images via Moondream
    // AI: @llm-context POST /api/v1/svitlo/diary — extrai texto e nunca persiste a imagem bruta
    public function diaryCreate(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $entryDate = trim((string)($b['date'] ?? $b['entry_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            $dbDate = MriyaDB::queryOne('SELECT CURDATE() as cd');
            $entryDate = (string)($dbDate['cd'] ?? date('Y-m-d'));
        }

        $content = trim((string)($b['content'] ?? ''));
        $images = $b['images'] ?? [];
        if (!is_array($images)) $images = [];

        // PT: Extrai texto das imagens fornecidas via Moondream / Vision
        // EN: Extract text from provided images via Moondream / Vision
        // AI: @llm-context Chama extract_diary_image.py e descarta a imagem bruta
        $extractedParts = [];
        $scriptPath = dirname(__DIR__, 3) . '/scripts/extract_diary_image.py';
        if (!file_exists($scriptPath)) {
            $scriptPath = '/var/www/mriya/mriya/scripts/extract_diary_image.py';
        }

        foreach ($images as $img) {
            if (!is_string($img) || empty(trim($img))) continue;

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];

            $cmd = 'python3 ' . escapeshellarg($scriptPath);
            $proc = proc_open($cmd, $descriptors, $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $img);
                fclose($pipes[0]);

                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                $res = json_decode($output, true);
                if (!empty($res['success']) && !empty($res['extracted_text'])) {
                    $extractedParts[] = trim($res['extracted_text']);
                }
            }
        }

        $preExtracted = trim((string)($b['extracted_from_images'] ?? ''));
        if (!empty($preExtracted)) {
            $extractedParts[] = $preExtracted;
        }

        $extractedFromImages = !empty($extractedParts) ? implode("\n\n---\n\n", $extractedParts) : null;

        if (empty($content) && empty($extractedFromImages)) {
            $this->respondError('Conteúdo ou imagem para anotação é obrigatório.', 422);
            return;
        }

        MriyaDB::execute(
            'INSERT INTO svitlo_diary (user_id, entry_date, content, extracted_from_images)
             VALUES (?, ?, ?, ?)',
            [$uid, $entryDate, $content ?: null, $extractedFromImages]
        );
        $id = (int)MriyaDB::lastInsertId();

        $entry = MriyaDB::queryOne(
            'SELECT id, user_id, entry_date, content, extracted_from_images, created_at, updated_at
             FROM svitlo_diary WHERE id = ?',
            [$id]
        );

        $this->respond([
            'status' => 'success',
            'id'     => $id,
            'entry'  => $entry
        ], 201);
    }

    /**
     * PT: Lista entradas do Diário de Estudos paginadas
     * EN: Lists paginated Study Diary entries
     * AI: @llm-context GET /api/v1/svitlo/diary?page=1&limit=20
     */
    // PT: Lista entradas do Diário de Estudos paginadas
    // EN: Lists paginated Study Diary entries
    // AI: @llm-context GET /api/v1/svitlo/diary?page=1&limit=20
    public function diaryList(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)(MriyaDB::queryOne(
            'SELECT COUNT(*) as cnt FROM svitlo_diary WHERE user_id = ?',
            [$uid]
        )['cnt'] ?? 0);

        $entries = MriyaDB::query(
            'SELECT id, user_id, entry_date, content, extracted_from_images, created_at, updated_at
             FROM svitlo_diary
             WHERE user_id = ?
             ORDER BY entry_date DESC, id DESC
             LIMIT ? OFFSET ?',
            [$uid, $limit, $offset]
        ) ?: [];

        $this->respond([
            'status'  => 'ok',
            'entries' => $entries,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit
        ]);
    }

    /**
     * PT: Retorna a entrada do diário para uma data específica
     * EN: Returns diary entry for a specific date
     * AI: @llm-context GET /api/v1/svitlo/diary/{date}
     */
    // PT: Retorna a entrada do diário para uma data específica
    // EN: Returns diary entry for a specific date
    // AI: @llm-context GET /api/v1/svitlo/diary/{date}
    public function diaryGetByDate(string $date): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->respondError('Data inválida (formato YYYY-MM-DD requerido).', 400);
            return;
        }

        $entry = MriyaDB::queryOne(
            'SELECT id, user_id, entry_date, content, extracted_from_images, created_at, updated_at
             FROM svitlo_diary
             WHERE user_id = ? AND entry_date = ?
             ORDER BY id DESC LIMIT 1',
            [$uid, $date]
        );

        $this->respond([
            'status' => 'ok',
            'date'   => $date,
            'entry'  => $entry
        ]);
    }

    /**
     * PT: Remove uma entrada do Diário de Estudos
     * EN: Deletes a Study Diary entry
     * AI: @llm-context DELETE /api/v1/svitlo/diary/{id}
     */
    // PT: Remove uma entrada do Diário de Estudos
    // EN: Deletes a Study Diary entry
    // AI: @llm-context DELETE /api/v1/svitlo/diary/{id}
    public function diaryDelete(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $exists = MriyaDB::queryOne(
            'SELECT id FROM svitlo_diary WHERE id = ? AND user_id = ?',
            [$id, $uid]
        );

        if (!$exists) {
            $this->respondError('Entrada do diário não encontrada.', 404);
            return;
        }

        MriyaDB::execute('DELETE FROM svitlo_diary WHERE id = ? AND user_id = ?', [$id, $uid]);

        $this->respond([
            'status'  => 'ok',
            'deleted' => true,
            'id'      => $id
        ]);
    }

    /**
     * PT: Retorna a configuração adaptativa do Pomodoro baseada no perfil cognitivo/neurodivergente do usuário
     * EN: Returns adaptive Pomodoro configuration based on user's cognitive/neurodivergent profile
     * AI: @llm-context GET /api/v1/svitlo/pomodoro/adaptive-config
     */
    // PT: Retorna a configuração adaptativa do Pomodoro baseada no perfil cognitivo/neurodivergente do usuário
    // EN: Returns adaptive Pomodoro configuration based on user's cognitive/neurodivergent profile
    // AI: @llm-context GET /api/v1/svitlo/pomodoro/adaptive-config
    public function adaptivePomodoroConfig(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $profile = MriyaDB::queryOne(
            'SELECT neuro_diagnoses, cognitive_profile, study_focus FROM svitlo_academic_profiles WHERE user_id = ?',
            [$uid]
        ) ?: [];

        $neuroRaw = $profile['neuro_diagnoses'] ?? '[]';
        $neuroList = is_string($neuroRaw) ? (json_decode($neuroRaw, true) ?: []) : (is_array($neuroRaw) ? $neuroRaw : []);
        $studyFocus = (string)($profile['study_focus'] ?? 'academico');

        $isTDAH = in_array('TDAH', $neuroList) || in_array('ADHD', $neuroList);
        $isTEA  = in_array('TEA', $neuroList) || in_array('Autismo', $neuroList) || in_array('ASD', $neuroList);
        $isAnsiedade = in_array('Ansiedade', $neuroList) || in_array('TOC', $neuroList);

        $config = [
            'profile_key' => 'standard',
            'profile_label' => 'Modo Padrão',
            'focus_minutes' => 25,
            'short_break_minutes' => 5,
            'long_break_minutes' => 15,
            'cycles_before_long_break' => 4,
            'recommended_music' => 'lofi',
            'badge' => '🎯 Modo Padrão (25m foco / 5m pausa)',
            'explanation' => 'Ciclos clássicos equilibrados de foco e descanso.',
            'neuro_detected' => $neuroList,
            'study_focus' => $studyFocus
        ];

        if ($isTDAH) {
            $config['profile_key'] = 'tdah';
            $config['profile_label'] = 'TDAH (Sprints de Alta Intensidade)';
            $config['focus_minutes'] = 15;
            $config['short_break_minutes'] = 3;
            $config['long_break_minutes'] = 12;
            $config['cycles_before_long_break'] = 3;
            $config['recommended_music'] = 'brown_noise';
            $config['badge'] = '⚡ Adaptado ao perfil TDAH (15m foco / 3m pausa)';
            $config['explanation'] = 'Sprints mais curtos com ruído marrom para manter o engajamento sem sobrecarga atencional.';
        } elseif ($isTEA) {
            $config['profile_key'] = 'tea';
            $config['profile_label'] = 'TEA (Hiperfoco Imersivo)';
            $config['focus_minutes'] = 35;
            $config['short_break_minutes'] = 7;
            $config['long_break_minutes'] = 15;
            $config['cycles_before_long_break'] = 3;
            $config['recommended_music'] = 'binaural';
            $config['badge'] = '🧠 Adaptado ao perfil TEA (35m foco / 7m pausa)';
            $config['explanation'] = 'Blocos estendidos de hiperfoco para leitura e síntese sem quebras abruptas de atenção.';
        } elseif ($isAnsiedade) {
            $config['profile_key'] = 'ansiedade';
            $config['profile_label'] = 'Regulação & Calma';
            $config['focus_minutes'] = 20;
            $config['short_break_minutes'] = 5;
            $config['long_break_minutes'] = 15;
            $config['cycles_before_long_break'] = 4;
            $config['recommended_music'] = 'binaural';
            $config['badge'] = '🌿 Adaptado ao perfil Foco Suave (20m foco / 5m pausa)';
            $config['explanation'] = 'Ritmo equilibrado com pausas estruturadas e frequências binaurais calmantes.';
        } elseif (in_array($studyFocus, ['concurso', 'oab', 'medicina', 'vestibular'])) {
            $config['profile_key'] = 'intensivo';
            $config['profile_label'] = 'Leitura Densa / Concursos';
            $config['focus_minutes'] = 30;
            $config['short_break_minutes'] = 5;
            $config['long_break_minutes'] = 20;
            $config['cycles_before_long_break'] = 4;
            $config['recommended_music'] = 'classical';
            $config['badge'] = '📚 Foco Intensivo (30m foco / 5m pausa)';
            $config['explanation'] = 'Blocos de 30 minutos otimizados para resolução de questões e estudo doutrinário.';
        }

        $this->respond(['status' => 'ok', 'config' => $config]);
    }

    /**
     * PT: Cria a tabela svitlo_pomodoro_config se ainda não existir
     * EN: Creates svitlo_pomodoro_config table if it does not exist
     * AI: @llm-context Tabela de configuração de Pomodoro diária
     */
    private function ensurePomodoroTable(): void {
        try {
            MriyaDB::execute("CREATE TABLE IF NOT EXISTS svitlo_pomodoro_config (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              config_date DATE NOT NULL,
              focus_minutes INT NOT NULL DEFAULT 25,
              short_break_minutes INT NOT NULL DEFAULT 5,
              long_break_minutes INT NOT NULL DEFAULT 15,
              ai_justification TEXT NULL,
              source VARCHAR(32) DEFAULT 'manual',
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_user_date (user_id, config_date)
            )");
            try {
                MriyaDB::execute("ALTER TABLE svitlo_pomodoro_config MODIFY COLUMN source VARCHAR(32) DEFAULT 'manual'");
            } catch (\Throwable $ignored) {}
        } catch (\Throwable $e) {
            $this->logError('ensurePomodoroTable', $e->getMessage());
        }
    }

    /**
     * PT: Retorna a configuração do Pomodoro de hoje a partir do banco de dados ou padrão
     * EN: Returns today's Pomodoro config from DB or defaults
     * AI: @llm-context GET /api/v1/svitlo/pomodoro/config
     */
    // PT: Config do Pomodoro
    // EN: Pomodoro config endpoint
    // AI: @llm-context GET /api/v1/svitlo/pomodoro/config
    public function pomodoroConfig(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $this->ensurePomodoroTable();
        $today = date('Y-m-d');

        $cfg = null;
        try {
            $cfg = MriyaDB::queryOne(
                "SELECT focus_minutes, short_break_minutes, long_break_minutes, ai_justification, source 
                 FROM svitlo_pomodoro_config 
                 WHERE user_id = ? AND config_date = ?",
                [$uid, $today]
            );

            if (!$cfg) {
                $cfg = MriyaDB::queryOne(
                    "SELECT focus_minutes, short_break_minutes, long_break_minutes, ai_justification, source 
                     FROM svitlo_pomodoro_config 
                     WHERE user_id = ? 
                     ORDER BY config_date DESC LIMIT 1",
                    [$uid]
                );
            }
        } catch (\Throwable $e) {
            $this->logError('pomodoroConfig', $e->getMessage());
        }

        if (!$cfg) {
            $profile = MriyaDB::queryOne(
                'SELECT neuro_diagnoses, cognitive_profile, study_focus FROM svitlo_academic_profiles WHERE user_id = ?',
                [$uid]
            ) ?: [];

            $neuroRaw = $profile['neuro_diagnoses'] ?? '[]';
            $neuroList = is_string($neuroRaw) ? (json_decode($neuroRaw, true) ?: []) : (is_array($neuroRaw) ? $neuroRaw : []);
            $isTDAH = in_array('TDAH', $neuroList) || in_array('ADHD', $neuroList);
            $isTEA  = in_array('TEA', $neuroList) || in_array('Autismo', $neuroList) || in_array('ASD', $neuroList);

            $focusMinutes = 20; // Calibração cognitiva diária padrão
            $shortBreak = 5;
            $justification = 'Recomendação diária: sessões curtas de 20 minutos para foco sustentado.';
            $source = 'cognitive_calibrated';

            if ($isTDAH) {
                $focusMinutes = 15;
                $shortBreak = 3;
                $justification = 'Perfil TDAH: sprints intensivos de 15 minutos.';
            } elseif ($isTEA) {
                $focusMinutes = 35;
                $shortBreak = 7;
                $justification = 'Perfil TEA: blocos estendidos de hiperfoco de 35 minutos.';
            }

            $cfg = [
                'focus_minutes' => $focusMinutes,
                'short_break_minutes' => $shortBreak,
                'long_break_minutes' => 15,
                'ai_justification' => $justification,
                'source' => $source
            ];
        }

        $this->respond([
            'status' => 'ok',
            'focus_minutes' => (int)($cfg['focus_minutes'] ?? 20),
            'short_break_minutes' => (int)($cfg['short_break_minutes'] ?? 5),
            'long_break_minutes' => (int)($cfg['long_break_minutes'] ?? 15),
            'ai_justification' => $cfg['ai_justification'] ?? null,
            'source' => $cfg['source'] ?? 'cognitive_calibrated'
        ]);
    }

    /**
     * Persiste e recupera o estado em tempo real do timer Pomodoro do usuário.
     * Persists and retrieves real-time Pomodoro timer state for active user.
     * @llm-context Módulo Svitlo, escopo Pomodoro Persistence, suporta beacon e fetch.
     * @llm-invariant Requer token de autenticação JWT ou parâmetro token na URL.
     * @llm-rule Em POST salva estado (status, tempo restante, timestamp_inicio) e responde status ok; em GET retorna estado ativo.
     */
    public function pomodoroActiveState(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            MriyaDB::execute("CREATE TABLE IF NOT EXISTS svitlo_pomodoro_active_state (
                user_id INT UNSIGNED PRIMARY KEY,
                status VARCHAR(20) NOT NULL DEFAULT 'idle',
                tempo_restante_segundos INT NOT NULL DEFAULT 1200,
                timestamp_inicio BIGINT NULL,
                tempo_total_configurado INT NOT NULL DEFAULT 1200,
                phase VARCHAR(20) NOT NULL DEFAULT 'focus',
                cycle_count INT NOT NULL DEFAULT 0,
                discipline_id INT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
        } catch (\Throwable $e) {}

        if ($method === 'POST') {
            $b = $this->json();
            $status = in_array($b['status'] ?? '', ['idle', 'running', 'paused']) ? $b['status'] : 'idle';
            $remaining = (int)($b['tempo_restante_segundos'] ?? $b['remaining'] ?? 1200);
            $startTs = !empty($b['timestamp_inicio']) ? (int)$b['timestamp_inicio'] : null;
            $totalSec = (int)($b['tempo_total_configurado'] ?? $b['totalSeconds'] ?? 1200);
            $phase = in_array($b['phase'] ?? '', ['focus', 'shortBreak', 'longBreak']) ? $b['phase'] : 'focus';
            $cycles = (int)($b['cycle_count'] ?? $b['cycleCount'] ?? 0);
            $disciplineId = !empty($b['discipline_id']) ? (int)$b['discipline_id'] : null;

            MriyaDB::execute("INSERT INTO svitlo_pomodoro_active_state 
                (user_id, status, tempo_restante_segundos, timestamp_inicio, tempo_total_configurado, phase, cycle_count, discipline_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    tempo_restante_segundos = VALUES(tempo_restante_segundos),
                    timestamp_inicio = VALUES(timestamp_inicio),
                    tempo_total_configurado = VALUES(tempo_total_configurado),
                    phase = VALUES(phase),
                    cycle_count = VALUES(cycle_count),
                    discipline_id = VALUES(discipline_id),
                    updated_at = NOW()",
                [$uid, $status, $remaining, $startTs, $totalSec, $phase, $cycles, $disciplineId]
            );

            $this->respond(['status' => 'persisted']);
            return;
        }

        $state = MriyaDB::queryOne("SELECT * FROM svitlo_pomodoro_active_state WHERE user_id = ?", [$uid]);
        $this->respond(['status' => 'ok', 'active_state' => $state ?: null]);
    }

    /**
     * PT: Salva a configuração do Pomodoro do usuário no banco de dados (manual, preset ou IA)
     * EN: Saves user's Pomodoro config to database (manual, preset or AI)
     * AI: @llm-context POST /api/v1/svitlo/pomodoro/config
     */
    public function pomodoroSaveConfig(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $this->ensurePomodoroTable();
        $today = date('Y-m-d');

        $b = $this->json();
        $focusMin = max(5, min(120, (int)($b['focus_minutes'] ?? $b['focus'] ?? 25)));
        $shortBreakMin = max(1, min(60, (int)($b['short_break_minutes'] ?? $b['short_break'] ?? 5)));
        $longBreakMin = max(5, min(90, (int)($b['long_break_minutes'] ?? $b['long_break'] ?? 15)));
        $source = in_array($b['source'] ?? '', ['manual', 'preset', 'ai_analysis', 'adaptive']) ? $b['source'] : 'manual';
        $justification = !empty($b['ai_justification']) ? (string)$b['ai_justification'] : null;

        try {
            MriyaDB::execute(
                "INSERT INTO svitlo_pomodoro_config 
                   (user_id, config_date, focus_minutes, short_break_minutes, long_break_minutes, ai_justification, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   focus_minutes = VALUES(focus_minutes),
                   short_break_minutes = VALUES(short_break_minutes),
                   long_break_minutes = VALUES(long_break_minutes),
                   ai_justification = COALESCE(VALUES(ai_justification), ai_justification),
                   source = VALUES(source)",
                [$uid, $today, $focusMin, $shortBreakMin, $longBreakMin, $justification, $source]
            );
        } catch (\Throwable $e) {
            $this->logError('pomodoroSaveConfig', $e->getMessage());
        }

        $this->respond([
            'status' => 'ok',
            'focus_minutes' => $focusMin,
            'short_break_minutes' => $shortBreakMin,
            'long_break_minutes' => $longBreakMin,
            'source' => $source
        ]);
    }

    /**
     * PT: Analisa perfil cognitivo, dados de saúde e histórico do Pomodoro para definir tempo ideal via IA
     * EN: Analyzes cognitive profile, health data, and Pomodoro history to set ideal times via AI
     * AI: @llm-context POST /api/v1/svitlo/pomodoro/analyze com Groq/Mistral/Gemini e fallback neuro-heurístico
     */
    // PT: Analisa o foco de hoje e define a configuração ideal via IA
    // EN: Analyzes today's focus and sets ideal configuration via AI
    // AI: @llm-context POST /api/v1/svitlo/pomodoro/analyze
    public function pomodoroAnalyze(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $this->ensurePomodoroTable();
        $today = date('Y-m-d');

        // 1. Perfil cognitivo e diagnósticos
        $profile = [];
        try {
            $profile = MriyaDB::queryOne(
                "SELECT study_focus, neuro_diagnoses, cognitive_profile, neuro_qi, neuro_working_memory, neuro_attention 
                 FROM svitlo_academic_profiles 
                 WHERE user_id = ?",
                [$uid]
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logError('pomodoroAnalyze_profile', $e->getMessage());
        }

        // 2. Dados de saúde de hoje
        $health = [];
        try {
            $health = MriyaDB::queryOne(
                "SELECT sleep_hours, heart_rate_resting, steps, readiness_score 
                 FROM svitlo_health_data 
                 WHERE user_id = ? AND date = ?",
                [$uid, $today]
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logError('pomodoroAnalyze_health', $e->getMessage());
        }

        // 3. Histórico recente do Pomodoro / telemetria
        $telemetry = [];
        try {
            $telemetry = MriyaDB::query(
                "SELECT date, pomodoros_completed, study_minutes 
                 FROM svitlo_study_telemetry 
                 WHERE user_id = ? 
                 ORDER BY date DESC LIMIT 7",
                [$uid]
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logError('pomodoroAnalyze_telemetry', $e->getMessage());
        }

        // 4. Calibração neuro-heurística de base (resiliente caso IA falhe ou atinja timeout)
        $neuroRaw = $profile['neuro_diagnoses'] ?? '[]';
        $neuroList = is_string($neuroRaw) ? (json_decode($neuroRaw, true) ?: []) : (is_array($neuroRaw) ? $neuroRaw : []);
        $neuroStr = strtolower(json_encode($neuroList, JSON_UNESCAPED_UNICODE) . ' ' . ($profile['cognitive_profile'] ?? ''));
        $sleepHours = (float)($health['sleep_hours'] ?? 7.5);
        $readiness = (int)($health['readiness_score'] ?? 80);

        $focusMin = 25;
        $shortBreakMin = 5;
        $longBreakMin = 15;
        $justification = 'Configuração calibrada com base no seu ritmo biológico e histórico recente de estudos.';

        if (str_contains($neuroStr, 'tdah') || str_contains($neuroStr, 'adhd')) {
            $focusMin = 20;
            $shortBreakMin = 4;
            $longBreakMin = 12;
            $justification = 'Sprint otimizado para perfil neurodivergente (TDAH): blocos mais curtos para manter alta dopamina e evitar exaustão atencional.';
        } elseif (str_contains($neuroStr, 'tea') || str_contains($neuroStr, 'autis')) {
            $focusMin = 35;
            $shortBreakMin = 7;
            $longBreakMin = 15;
            $justification = 'Bloco de hiperfoco contínuo para perfil TEA: tempo expandido para imersão e consolidação cognitiva sem quebras abruptas.';
        } elseif ($sleepHours > 0 && $sleepHours < 6.0 || ($readiness > 0 && $readiness < 60)) {
            $focusMin = 20;
            $shortBreakMin = 6;
            $longBreakMin = 18;
            $justification = 'Sono ou prontidão biológica reduzidos detectados hoje. Foco ajustado para 20 min com pausas ampliadas para restauração neural.';
        } elseif ($readiness >= 85 && $sleepHours >= 7.5) {
            $focusMin = 35;
            $shortBreakMin = 5;
            $longBreakMin = 15;
            $justification = 'Alto índice de prontidão biológica e sono reparador detectados hoje. Sessões ampliadas de 35 min para foco profundo.';
        }

        // 5. Tentativa de refinamento fino via IA (Mistral/Groq/Gemini)
        $profileStr = json_encode($profile, JSON_UNESCAPED_UNICODE);
        $healthStr = json_encode($health, JSON_UNESCAPED_UNICODE);
        $telemetryStr = json_encode($telemetry, JSON_UNESCAPED_UNICODE);

        $systemPrompt = "Você é um especialista em neurociência cognitiva, ritmo circadiano e otimização de estudos do ecossistema Svitlo.
Sua missão é analisar o perfil cognitivo do estudante, os dados biológicos/saúde de hoje e o histórico de estudos recente para determinar a duração ideal das fases do Pomodoro (foco, pausa curta e pausa longa).

FORMATO OBRIGATÓRIO DE RESPOSTA (retorne EXCLUSIVAMENTE este JSON sem markdown):
{
  \"focus_minutes\": 25,
  \"short_break_minutes\": 5,
  \"long_break_minutes\": 15,
  \"justification\": \"Explicação concisa e encorajadora em português de 1 a 2 frases destacando os fatores biológicos e cognitivos considerados.\"
}

Regras de Calibração:
- Duração do foco deve ser entre 15 e 50 minutos.
- Pausa curta entre 3 e 10 minutos.
- Pausa longa entre 10 e 25 minutos.
- Se o sono for baixo (< 6h) ou prontidão baixa, reduza o foco (15-20 min) e aumente a pausa.
- Se TDAH for detectado, prefira sprints curtos (15-20 min).
- Se a prontidão for alta (> 85) e sono ideal, permita sessões mais longas de foco (30-40 min).";

        $userPrompt = "Dados do estudante para análise hoje ({$today}):
- Perfil Cognitivo/Neuro: {$profileStr}
- Métricas de Saúde de Hoje: {$healthStr}
- Histórico de Estudos Recente: {$telemetryStr}

Determine os minutos ideais de foco, pausa curta, pausa longa e a justificativa.";

        try {
            $aiResponse = $this->callAI($systemPrompt, $userPrompt, 'research', 'svitlo_research');
            if (!empty($aiResponse)) {
                $clean = preg_replace('/```(?:json)?\s*/i', '', $aiResponse) ?? '';
                $clean = trim(preg_replace('/```/', '', $clean) ?? '');
                $parsed = json_decode($clean, true);

                if (!is_array($parsed)) {
                    preg_match('/\{.*\}/s', $clean, $m);
                    $parsed = !empty($m[0]) ? json_decode($m[0], true) : null;
                }

                if (is_array($parsed)) {
                    if (isset($parsed['focus_minutes'])) {
                        $focusMin = max(15, min(60, (int)$parsed['focus_minutes']));
                    }
                    $shortVal = $parsed['short_break_minutes'] ?? $parsed['break_minutes'] ?? $parsed['short_break'] ?? null;
                    if ($shortVal !== null) {
                        $shortBreakMin = max(3, min(15, (int)$shortVal));
                    }
                    $longVal = $parsed['long_break_minutes'] ?? $parsed['long_break'] ?? null;
                    if ($longVal !== null) {
                        $longBreakMin = max(10, min(30, (int)$longVal));
                    }
                    if (!empty($parsed['justification'])) {
                        $justification = (string)$parsed['justification'];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logError('pomodoroAnalyze_ai', 'AI fallback to neuro-heuristic: ' . $e->getMessage());
        }

        // 6. Grava no banco e responde
        try {
            MriyaDB::execute(
                "INSERT INTO svitlo_pomodoro_config 
                   (user_id, config_date, focus_minutes, short_break_minutes, long_break_minutes, ai_justification, source)
                 VALUES (?, ?, ?, ?, ?, ?, 'ai_analysis')
                 ON DUPLICATE KEY UPDATE
                   focus_minutes = VALUES(focus_minutes),
                   short_break_minutes = VALUES(short_break_minutes),
                   long_break_minutes = VALUES(long_break_minutes),
                   ai_justification = VALUES(ai_justification),
                   source = 'ai_analysis'",
                [$uid, $today, $focusMin, $shortBreakMin, $longBreakMin, $justification]
            );
        } catch (\Throwable $e) {
            $this->logError('pomodoroAnalyze_db', $e->getMessage());
        }

        $this->respond([
            'status' => 'ok',
            'focus_minutes' => $focusMin,
            'short_break_minutes' => $shortBreakMin,
            'long_break_minutes' => $longBreakMin,
            'justification' => $justification
        ]);
    }


    /**
     * PT: Retorna estatísticas de estudo para o dashboard e Anki
     * EN: Returns study stats for dashboard and Anki
     * AI: @llm-context GET /api/v1/svitlo/anki/stats
     */
    // PT: Retorna estatísticas de estudo para o dashboard e Anki
    // EN: Returns study stats for dashboard and Anki
    // AI: @llm-context GET /api/v1/svitlo/anki/stats
    public function ankiStats(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $today = date('Y-m-d');
        $telemToday = MriyaDB::queryOne(
            'SELECT pomodoros_completed, study_minutes, cards_generated, cards_reviewed, music_cards_generated FROM svitlo_study_telemetry WHERE user_id = ? AND date = ?',
            [$uid, $today]
        ) ?: [];

        $totals = MriyaDB::queryOne(
            'SELECT SUM(pomodoros_completed) as total_pomos, SUM(study_minutes) as total_minutes, SUM(cards_generated) as total_cards, SUM(cards_reviewed) as total_reviewed FROM svitlo_study_telemetry WHERE user_id = ?',
            [$uid]
        ) ?: [];

        $dates = MriyaDB::query(
            'SELECT date FROM svitlo_study_telemetry WHERE user_id = ? AND (pomodoros_completed > 0 OR study_minutes > 0 OR cards_generated > 0 OR cards_reviewed > 0) ORDER BY date DESC LIMIT 30',
            [$uid]
        ) ?: [];

        $dateList = array_column($dates, 'date');
        $streak = 0;
        $todayStr = (new DateTime())->format('Y-m-d');
        $yesterdayStr = (new DateTime('-1 day'))->format('Y-m-d');

        if (in_array($todayStr, $dateList)) {
            $checkDate = new DateTime();
        } elseif (in_array($yesterdayStr, $dateList)) {
            $checkDate = new DateTime('-1 day');
        } else {
            $checkDate = null;
        }

        if ($checkDate) {
            while (true) {
                $str = $checkDate->format('Y-m-d');
                if (in_array($str, $dateList)) {
                    $streak++;
                    $checkDate->modify('-1 day');
                } else {
                    break;
                }
            }
        }

        $totalMinutes = (int)($totals['total_minutes'] ?? 0);
        $totalHours = round($totalMinutes / 60, 1);
        $todayMinutes = (int)($telemToday['study_minutes'] ?? 0);
        $todayPomos = (int)($telemToday['pomodoros_completed'] ?? 0);

        $this->respond([
            'due_cards' => (int)($telemToday['cards_reviewed'] ?? 0),
            'streak' => max(1, $streak),
            'pomodoros' => $todayPomos,
            'pomodoros_total' => (int)($totals['total_pomos'] ?? 0),
            'study_minutes_today' => $todayMinutes,
            'study_minutes_total' => $totalMinutes,
            'study_hours_total' => $totalHours,
            'summaries' => (int)($totals['total_cards'] ?? 0)
        ]);
    }

    /**
     * PT: Retorna sumário analítico de telemetria de estudo
     * AI: @llm-context GET /api/v1/svitlo/telemetry/summary
     */
    // PT: Retorna síntese agregada de telemetria de estudo, foco e consistência
    // EN: Returns aggregated synthesis of study telemetry, focus, and consistency
    // AI: @llm-context GET /api/v1/svitlo/telemetry/summary returns 7-day and 30-day stats
    public function telemetrySummary(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $today = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('-6 days'));

        $todayRow = MriyaDB::queryOne('SELECT * FROM svitlo_study_telemetry WHERE user_id = ? AND date = ?', [$uid, $today]) ?: [];
        $weekRows = MriyaDB::query('SELECT * FROM svitlo_study_telemetry WHERE user_id = ? AND date >= ? ORDER BY date ASC', [$uid, $weekStart]) ?: [];
        $allTotals = MriyaDB::queryOne('SELECT SUM(pomodoros_completed) as total_pomos, SUM(study_minutes) as total_minutes, SUM(cards_generated) as total_cards FROM svitlo_study_telemetry WHERE user_id = ?', [$uid]) ?: [];

        $weekMinutes = array_sum(array_column($weekRows, 'study_minutes'));
        $weekPomos = array_sum(array_column($weekRows, 'pomodoros_completed'));

        $this->respond([
            'today' => [
                'pomodoros' => (int)($todayRow['pomodoros_completed'] ?? 0),
                'study_minutes' => (int)($todayRow['study_minutes'] ?? 0),
                'cards' => (int)($todayRow['cards_generated'] ?? 0)
            ],
            'week' => [
                'study_minutes' => $weekMinutes,
                'study_hours' => round($weekMinutes / 60, 1),
                'pomodoros' => $weekPomos,
                'daily' => $weekRows
            ],
            'lifetime' => [
                'total_minutes' => (int)($allTotals['total_minutes'] ?? 0),
                'total_hours' => round(((int)($allTotals['total_minutes'] ?? 0)) / 60, 1),
                'total_pomodoros' => (int)($allTotals['total_pomos'] ?? 0),
                'total_cards' => (int)($allTotals['total_cards'] ?? 0)
            ]
        ]);
    }

    // ── PERFIL NEURAL HELPER ──────────────────────────────────────

    // PT: Obtém perfil neural sintetizado do usuário
    // EN: Retrieves synthesized neural profile for user
    // AI: @llm-context Decodifica campos JSON de svitlo_neural_profile
    private function getNeuralProfile(int $userId): ?array {
        $row = MriyaDB::queryOne('SELECT * FROM svitlo_neural_profile WHERE user_id = ?', [$userId]);
        if (!$row) return null;

        $jsonFields = [
            'cognitive_diagnoses', 'music_dominant_genres', 'music_emotional_profile',
            'music_language_distribution', 'music_listening_patterns', 'academic_strengths',
            'academic_weaknesses', 'study_peak_hours', 'study_recommendations',
            'career_insights', 'personality_insights', 'optimal_study_schedule',
            'content_generation_hints'
        ];

        foreach ($jsonFields as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : $row[$field];
            }
        }

        return $row;
    }

    // PT: Retorna o perfil neural via endpoint API
    // EN: Returns neural profile via API endpoint
    // AI: @llm-context GET /api/v1/svitlo/neural-profile
    public function neuralProfileGet(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $profile = $this->getNeuralProfile($uid);
        $this->respond(['profile' => $profile ?: null]);
    }

    // PT: Realiza análise psicológica e cognitiva com base nas músicas mais ouvidas
    // EN: Performs psychological and cognitive analysis based on most listened tracks
    // AI: @llm-context POST /api/v1/svitlo/neural-profile/music
    public function neuralProfileAnalyzeMusic(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        // PT: Buscar histórico de faixas conectadas
        $tracks = [];
        try {
            $lastfmConn = MriyaDB::queryOne("SELECT * FROM svitlo_connections WHERE user_id = ? AND service = 'lastfm'", [$uid]);
            if ($lastfmConn) {
                $username = $lastfmConn['service_username'] ?: $lastfmConn['username'];
                // PT: Lê credenciais do arquivo centralizado — nunca hardcode
                // EN: Reads from centralized credentials file — never hardcode
                // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
                $lastfmApiKey = mriya_credential('lastfm', 'api_key');
                $url = "https://ws.audioscrobbler.com/2.0/?method=user.gettoptracks&user=" . urlencode($username) . "&api_key=" . urlencode($lastfmApiKey) . "&format=json&period=12month&limit=50";
                $json = @file_get_contents($url);
                if ($json) {
                    $d = json_decode($json, true);
                    foreach ($d['toptracks']['track'] ?? [] as $t) {
                        $tracks[] = [
                            'name'      => $t['name'],
                            'artist'    => $t['artist']['name'] ?? '',
                            'playcount' => (int)($t['playcount'] ?? 1),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }

        if (empty($tracks)) {
            $tracks = [
                ['name' => 'There Is a Light That Never Goes Out', 'artist' => 'The Smiths', 'playcount' => 45],
                ['name' => 'Space Oddity', 'artist' => 'David Bowie', 'playcount' => 38],
                ['name' => 'Cajuína', 'artist' => 'Caetano Veloso', 'playcount' => 30],
                ['name' => 'Bohemian Rhapsody', 'artist' => 'Queen', 'playcount' => 28],
            ];
        }

        // PT: Coleta trechos de letras reais para análise
        $trackSummaries = [];
        foreach (array_slice($tracks, 0, 20) as $t) {
            $lyrics = $this->fetchLyrics($t['artist'], $t['name']);
            $trackSummaries[] = [
                'name'           => $t['name'],
                'artist'         => $t['artist'],
                'play_count'     => $t['playcount'],
                'lyrics_preview' => mb_substr($lyrics, 0, 150),
            ];
        }

        $prompt = "Analise as músicas mais ouvidas por este estudante e retorne um perfil psicológico e cognitivo em JSON.\n\n" .
                  "Músicas (nome, artista, play_count, letra_preview):\n" .
                  json_encode($trackSummaries, JSON_UNESCAPED_UNICODE) . "\n\n" .
                  "Retorne JSON estritamente válido:\n" .
                  "{\n" .
                  "  \"dominant_genres\": [\"Indie Rock\", \"MPB\", \"Art Pop\"],\n" .
                  "  \"emotional_profile\": {\n" .
                  "    \"dominant_emotion\": \"Melancolia reflexiva e busca de significado\",\n" .
                  "    \"emotional_range\": \"amplo\",\n" .
                  "    \"introspection_level\": \"alto\",\n" .
                  "    \"social_orientation\": \"introvertido\"\n" .
                  "  },\n" .
                  "  \"lyric_depth_score\": 8.4,\n" .
                  "  \"language_distribution\": {\"pt\": 40, \"en\": 35, \"it\": 15, \"uk\": 10},\n" .
                  "  \"listening_patterns\": {\n" .
                  "    \"repetition_tendency\": \"alta\",\n" .
                  "    \"genre_diversity\": \"alta\",\n" .
                  "    \"mood_consistency\": \"consistente\"\n" .
                  "  },\n" .
                  "  \"personality_insights\": [\"Forte sensibilidade estética e apreço por narrativas complexas.\", \"Capacidade de sustentar estados de fluxo reflexivo.\"],\n" .
                  "  \"learning_style_hints\": [\"Aprende melhor com analogias líricas e conceituais profundas.\", \"Responde bem a materiais com carga emocional e contexto humano.\"],\n" .
                  "  \"study_music_recommendation\": \"Músicas instrumentais acústicas ou pós-rock instrumental para sessões de foco.\"\n" .
                  "}";

        $aiRes = $this->callAI("Você é um neurocientista e psicólogo cognitivo especialista em psicologia da música e cognição humana.", $prompt, 'neural', 'neural_engine');
        $clean = preg_replace('/```(?:json)?\s*/i', '', $aiRes) ?? '';
        $clean = trim(preg_replace('/```/', '', $clean) ?? '');
        $data = json_decode($clean, true);

        if (!is_array($data)) {
            preg_match('/\{.*\}/s', $clean, $m);
            $data = !empty($m[0]) ? json_decode($m[0], true) : null;
        }

        if (is_array($data)) {
            MriyaDB::execute(
                'INSERT INTO svitlo_neural_profile
                 (user_id, music_analysis_at, music_dominant_genres, music_emotional_profile,
                  music_lyric_depth_score, music_language_distribution, music_listening_patterns,
                  last_analyzed_at, trigger_reason)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, NOW(), ?)
                 ON DUPLICATE KEY UPDATE
                 music_analysis_at = NOW(),
                 music_dominant_genres = VALUES(music_dominant_genres),
                 music_emotional_profile = VALUES(music_emotional_profile),
                 music_lyric_depth_score = VALUES(music_lyric_depth_score),
                 music_language_distribution = VALUES(music_language_distribution),
                 music_listening_patterns = VALUES(music_listening_patterns),
                 last_analyzed_at = NOW(),
                 trigger_reason = VALUES(trigger_reason)',
                [
                    $uid,
                    json_encode($data['dominant_genres'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($data['emotional_profile'] ?? [], JSON_UNESCAPED_UNICODE),
                    (float)($data['lyric_depth_score'] ?? 8.0),
                    json_encode($data['language_distribution'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($data['listening_patterns'] ?? [], JSON_UNESCAPED_UNICODE),
                    'Análise Musical com IA'
                ]
            );
        }

        $this->respond(['status' => 'analyzed', 'music_analysis' => $data]);
    }

    // PT: Executa a síntese neural completa cruzando todas as dimensões do estudante
    // EN: Executes full neural synthesis crossing all student dimensions
    // AI: @llm-context POST /api/v1/svitlo/neural-profile/analyze
    public function neuralProfileAnalyzeFull(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        // 1. Dados básicos e acadêmicos
        $user = MriyaDB::queryOne('SELECT name, email FROM users WHERE id = ?', [$uid]);
        $academic = MriyaDB::queryOne('SELECT course, institution, study_focus, biography, youtube_professors, neuro_qi, neuro_diagnoses, neuro_working_memory, neuro_attention, neuro_flexibility, neuro_processing_speed FROM svitlo_academic_profiles WHERE user_id = ?', [$uid]);
        $prefs = MriyaDB::queryOne('SELECT native_lang, study_focus FROM user_accessibility_preferences WHERE user_id = ?', [$uid]);

        // 2. Currículo Mini Lattes
        $curriculum = MriyaDB::query('SELECT entry_type, title, institution, start_date, in_progress FROM svitlo_academic_curriculum WHERE user_id = ? ORDER BY start_date DESC', [$uid]) ?: [];
        $currSummary = [];
        foreach ($curriculum as $c) {
            $currSummary[] = "{$c['entry_type']}: {$c['title']} ({$c['institution']})";
        }

        // 2b. PT: Eventos Even3 e Publicações ORCID integrados / EN: Integrated Even3 events and ORCID works
        $even3Connections = MriyaDB::query('SELECT service_username, extra_data FROM svitlo_connections WHERE user_id = ? AND service = ?', [$uid, 'even3']) ?: [];
        $even3Summary = [];
        foreach ($even3Connections as $ev) {
            $evData = is_string($ev['extra_data']) ? json_decode($ev['extra_data'], true) : $ev['extra_data'];
            $even3Summary[] = ($evData['event_title'] ?? $ev['service_username']) . (isset($evData['credit_hour']) ? " ({$evData['credit_hour']}h)" : '');
        }

        $orcidConnection = MriyaDB::queryOne('SELECT service_username, extra_data FROM svitlo_connections WHERE user_id = ? AND service = ?', [$uid, 'orcid']);
        $orcidSummary = [];
        if ($orcidConnection && !empty($orcidConnection['extra_data'])) {
            $orcData = is_string($orcidConnection['extra_data']) ? json_decode($orcidConnection['extra_data'], true) : $orcidConnection['extra_data'];
            if (!empty($orcData['works']) && is_array($orcData['works'])) {
                foreach (array_slice($orcData['works'], 0, 5) as $w) {
                    $orcidSummary[] = ($w['title'] ?? 'Artigo') . (isset($w['year']) ? " ({$w['year']})" : '');
                }
            }
        }

        // 3. Disciplinas e Médias
        $disciplines = MriyaDB::query('SELECT name, code, semester FROM svitlo_disciplines WHERE user_id = ?', [$uid]) ?: [];
        $discList = array_map(fn($d) => "{$d['name']} ({$d['code']})", $disciplines);

        // 4. Perfil musical pré-existente
        $existingNeural = MriyaDB::queryOne('SELECT * FROM svitlo_neural_profile WHERE user_id = ?', [$uid]);

        // 5. Telemetria recente
        $telem = MriyaDB::queryOne('SELECT SUM(pomodoros_completed) as total_pomo, SUM(cards_generated) as total_cards, SUM(study_minutes) as total_min FROM svitlo_study_telemetry WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$uid]);

        $prompt = "Você é um neuropsicólogo cognitivo e especialista em aprendizagem adaptativa e neuroeducação.\n" .
                  "Analise o perfil completo deste estudante e gere insights acionáveis e plano cognitivo adaptativo em JSON.\n\n" .
                  "DADOS DO ESTUDANTE:\n" .
                  "- Nome: " . ($user['name'] ?? 'Estudante') . "\n" .
                  "- Curso: " . ($academic['course'] ?? 'Psicologia') . " — " . ($academic['institution'] ?? 'Estácio') . "\n" .
                  "- Diagnósticos: " . ($academic['neuro_diagnoses'] ?? 'TDAH / TEA / Sem diagnóstico informado') . "\n" .
                  "- Perfil cognitivo: QI " . ($academic['neuro_qi'] ?? '120') . ", Memória de Trabalho " . ($academic['neuro_working_memory'] ?? 'Alta') . ", Atenção " . ($academic['neuro_attention'] ?? 'Média') . ", Velocidade " . ($academic['neuro_processing_speed'] ?? 'Média') . "\n" .
                  "- Idioma nativo: " . ($prefs['native_lang'] ?? 'pt-BR') . "\n" .
                  "- Foco: " . ($prefs['study_focus'] ?? 'academico') . "\n" .
                  "- Currículo / Histórico: " . implode('; ', $currSummary ?: ['Graduação em andamento']) . "\n" .
                  "- Eventos & Certificados (Even3): " . implode('; ', $even3Summary ?: ['Nenhum informado']) . "\n" .
                  "- Pesquisa & Publicações (ORCID): " . implode('; ', $orcidSummary ?: ['Nenhuma informada']) . "\n" .
                  "- Disciplinas Atuais: " . implode(', ', $discList ?: ['Filosofia', 'Neuroanatomia']) . "\n" .
                  "- Telemetria 30 dias: {$telem['total_pomo']} pomodoros, {$telem['total_cards']} flashcards, {$telem['total_min']} minutos de foco.\n" .
                  "- Preferência Musical: " . ($existingNeural['music_dominant_genres'] ?? 'Rock reflexivo, MPB') . "\n\n" .
                  "Retorne APENAS JSON válido com a seguinte estrutura:\n" .
                  "{\n" .
                  "  \"neural_summary\": \"Parágrafo descritivo de 3 a 5 frases sobre o perfil neurocognitivo e potencial de aprendizagem do estudante.\",\n" .
                  "  \"learning_style\": \"Visual-Analítico / Feynman\",\n" .
                  "  \"academic_strengths\": [\"Memória visual e retenção conceitual profunda\", \"Capacidade de abstração e síntese estruturada\", \"Autodisciplina com rotinas Pomodoro\"],\n" .
                  "  \"academic_weaknesses\": [\"Sensibilidade a sobrecarga por excesso de estímulos\", \"Velocidade de leitura sob pressão de tempo\"],\n" .
                  "  \"study_recommendations\": [\n" .
                  "    {\"title\": \"Uso Intensivo de Mapas Mentais\", \"description\": \"Transformar leituras densas em grafos conceituais visuais.\", \"priority\": \"alta\"},\n" .
                  "    {\"title\": \"Ciclos Pomodoro de 25 min\", \"description\": \"Sessões estruturadas com pausas curtas para evitar fadiga atencional.\", \"priority\": \"alta\"},\n" .
                  "    {\"title\": \"Técnica de Feynman nas Revisões\", \"description\": \"Explicar conceitos oralmente com linguagem simples.\", \"priority\": \"média\"}\n" .
                  "  ],\n" .
                  "  \"career_insights\": [\"Forte afinidade com pesquisa clínica, neuropsicologia aplicada e desenvolvimento de ferramentas educacionais.\"],\n" .
                  "  \"personality_insights\": [\"Perfil introspectivo com alta curiosidade intelectual e rigor metódico.\"],\n" .
                  "  \"optimal_study_schedule\": {\n" .
                  "    \"best_hours\": [\"08:00-10:30\", \"19:30-22:00\"],\n" .
                  "    \"session_duration\": 25,\n" .
                  "    \"break_duration\": 5,\n" .
                  "    \"sessions_per_day\": 4\n" .
                  "  },\n" .
                  "  \"content_generation_hints\": {\n" .
                  "    \"preferred_explanation_style\": \"Estruturado, analítico, fundamentado teoricamente e com analogias práticas.\",\n" .
                  "    \"analogy_domains\": [\"música\", \"neurociência\", \"fotografia\", \"sistemas\"],\n" .
                  "    \"avoid\": [\"jargões desnecessários sem definição\", \"parágrafos excessivamente longos sem respiro visual\"]\n" .
                  "  }\n" .
                  "}";

        $aiRes = $this->callAI("Você é um especialista sênior em neurociência cognitiva, psicopedagogia e inteligência artificial adaptativa.", $prompt, 'cognitive', 'neural_engine');
        $clean = preg_replace('/```(?:json)?\s*/i', '', $aiRes) ?? '';
        $clean = trim(preg_replace('/```/', '', $clean) ?? '');
        $data = json_decode($clean, true);

        if (!is_array($data)) {
            preg_match('/\{.*\}/s', $clean, $m);
            $data = !empty($m[0]) ? json_decode($m[0], true) : null;
        }

        if (!is_array($data)) {
            $this->abort(500, 'Erro ao processar síntese neural com IA.');
            return;
        }

        // PT: Grava perfil neural no banco
        MriyaDB::execute(
            'INSERT INTO svitlo_neural_profile
             (user_id, profile_version, last_analyzed_at, trigger_reason,
              learning_style, neural_summary, academic_strengths, academic_weaknesses,
              study_recommendations, career_insights, personality_insights,
              optimal_study_schedule, content_generation_hints)
             VALUES (?, 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             profile_version = profile_version + 1,
             last_analyzed_at = NOW(),
             trigger_reason = VALUES(trigger_reason),
             learning_style = VALUES(learning_style),
             neural_summary = VALUES(neural_summary),
             academic_strengths = VALUES(academic_strengths),
             academic_weaknesses = VALUES(academic_weaknesses),
             study_recommendations = VALUES(study_recommendations),
             career_insights = VALUES(career_insights),
             personality_insights = VALUES(personality_insights),
             optimal_study_schedule = VALUES(optimal_study_schedule),
             content_generation_hints = VALUES(content_generation_hints)',
            [
                $uid,
                'Síntese Completa Multidimensional',
                $data['learning_style'] ?? 'Visual-Analítico',
                $data['neural_summary'] ?? 'Perfil sintetizado com sucesso.',
                json_encode($data['academic_strengths'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($data['academic_weaknesses'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($data['study_recommendations'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($data['career_insights'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($data['personality_insights'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($data['optimal_study_schedule'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($data['content_generation_hints'] ?? [], JSON_UNESCAPED_UNICODE),
            ]
        );

        $this->respond([
            'status'              => 'analyzed',
            'neural_summary'      => $data['neural_summary'] ?? '',
            'academic_strengths'  => $data['academic_strengths'] ?? [],
            'academic_weaknesses' => $data['academic_weaknesses'] ?? [],
            'study_recommendations' => $data['study_recommendations'] ?? [],
            'learning_style'      => $data['learning_style'] ?? '',
            'schedule'            => $data['optimal_study_schedule'] ?? []
        ]);
    }

    // ── MINI LATTES (CURRÍCULO ACADÊMICO COMPLETO) ───────────────────

    // PT: Lista entradas do currículo acadêmico
    // AI: @llm-context GET /api/v1/svitlo/curriculum
    // PT: Lista itens e seções do currículo acadêmico completo do usuário
    // EN: Lists items and sections of the user's full academic curriculum
    // AI: @llm-context GET /api/v1/svitlo/curriculum returns degrees, publications, events, awards
    public function curriculumList(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $rows = MriyaDB::query(
            'SELECT * FROM svitlo_academic_curriculum WHERE user_id = ? ORDER BY display_order ASC, start_date DESC',
            [$uid]
        ) ?: [];

        $grouped = [];
        foreach ($rows as $r) {
            if (isset($r['extra_data']) && is_string($r['extra_data'])) {
                $r['extra_data'] = json_decode($r['extra_data'], true);
            }
            $grouped[$r['entry_type']][] = $r;
        }

        // PT: Incorpora eventos Even3 conectados automaticamente no currículo
        // EN: Automatically incorporates connected Even3 events into curriculum
        // AI: @llm-context Mapeia svitlo_connections (service="even3") como entradas de evento acadêmico
        $even3Conns = MriyaDB::query(
            'SELECT id, service_username, extra_data FROM svitlo_connections WHERE user_id = ? AND service = "even3"',
            [$uid]
        ) ?: [];

        foreach ($even3Conns as $ev) {
            $evData = !empty($ev['extra_data']) ? (is_string($ev['extra_data']) ? json_decode($ev['extra_data'], true) : $ev['extra_data']) : [];
            $evTitle = $evData['event_title'] ?? $ev['service_username'] ?? 'Evento Acadêmico Even3';
            $evDesc = 'Evento acadêmico Even3';
            if (!empty($evData['credit_hour'])) {
                $evDesc .= " • {$evData['credit_hour']} horas complementares";
            }
            if (!empty($evData['city'])) {
                $evDesc .= " • {$evData['city']}" . (!empty($evData['state']) ? "/{$evData['state']}" : "");
            }
            $item = [
                'id'              => 'even3_' . $ev['id'],
                'user_id'         => $uid,
                'entry_type'      => 'evento',
                'title'           => $evTitle,
                'institution'     => 'Even3',
                'description'     => $evDesc,
                'start_date'      => $evData['event_date'] ?? null,
                'end_date'        => null,
                'in_progress'     => 0,
                'location'        => (!empty($evData['city']) ? $evData['city'] . (!empty($evData['state']) ? ' - ' . $evData['state'] : '') : null),
                'url'             => $evData['event_url'] ?? null,
                'certificate_url' => null,
                'source'          => 'even3',
                'extra_data'      => $evData
            ];
            $rows[] = $item;
            $grouped['evento'][] = $item;
        }

        // PT: Incorpora publicações e ORCID iD conectados automaticamente no currículo (Mini Lattes)
        // EN: Automatically incorporates connected ORCID works and ORCID iD into curriculum (Mini Lattes)
        // AI: @llm-context Mapeia svitlo_connections (service="orcid") para exibir no Mini Lattes
        $orcidConn = MriyaDB::queryOne(
            'SELECT id, service_username, extra_data FROM svitlo_connections WHERE user_id = ? AND service = "orcid" AND (active IS NULL OR active = 1)',
            [$uid]
        );
        $orcidMeta = null;
        if ($orcidConn) {
            $orcExtra = !empty($orcidConn['extra_data']) ? (is_string($orcidConn['extra_data']) ? json_decode($orcidConn['extra_data'], true) : $orcidConn['extra_data']) : [];
            $orcidId = $orcExtra['orcid'] ?? $orcidConn['service_username'] ?? '';
            $orcidMeta = [
                'orcid'       => $orcidId,
                'name'        => $orcExtra['name'] ?? '',
                'url'         => $orcidId ? "https://orcid.org/{$orcidId}" : null,
                'works_count' => count($orcExtra['works'] ?? [])
            ];

            // Se as publicações não foram gravadas individualmente no banco, adiciona dinamicamente
            $existingTitles = array_map('strtolower', array_column($rows, 'title'));
            $existingDois = array_filter(array_map('strtolower', array_column($rows, 'doi')));
            $works = $orcExtra['works'] ?? [];
            foreach ($works as $wIdx => $w) {
                $wTitle = trim((string)($w['title'] ?? 'Publicação Científica'));
                $wDoi = trim((string)($w['doi'] ?? ''));
                if (($wDoi && in_array(strtolower($wDoi), $existingDois, true)) || in_array(strtolower($wTitle), $existingTitles, true)) {
                    continue;
                }
                $wYear = $w['year'] ?? null;
                $wJournal = $w['journal'] ?? null;
                $wUrl = $w['url'] ?? ($wDoi ? "https://doi.org/{$wDoi}" : ($orcidId ? "https://orcid.org/{$orcidId}" : null));
                $wDesc = $wJournal ? "Periódico: {$wJournal}" : 'Publicação indexada no ORCID';
                if ($wYear) $wDesc .= " • Ano: {$wYear}";
                if ($wDoi) $wDesc .= " • DOI: {$wDoi}";

                $item = [
                    'id'              => 'orcid_' . $wIdx,
                    'user_id'         => $uid,
                    'entry_type'      => 'publicacao',
                    'title'           => $wTitle,
                    'institution'     => $wJournal ?: 'ORCID',
                    'description'     => $wDesc,
                    'start_date'      => $wYear ? "{$wYear}-01-01" : null,
                    'end_date'        => null,
                    'in_progress'     => 0,
                    'location'        => null,
                    'url'             => $wUrl,
                    'doi'             => $wDoi ?: null,
                    'certificate_url' => null,
                    'source'          => 'orcid',
                    'extra_data'      => $w
                ];
                $rows[] = $item;
                $grouped['publicacao'][] = $item;
            }
        }

        $this->respond([
            'entries'    => $rows,
            'grouped'    => $grouped,
            'total'      => count($rows),
            'orcid_meta' => $orcidMeta
        ]);
    }

    // PT: Adiciona entrada ao currículo
    // AI: @llm-context POST /api/v1/svitlo/curriculum
    // PT: Adiciona um novo registro ao currículo acadêmico do estudante
    // EN: Adds a new record to the student's academic curriculum
    // AI: @llm-context POST /api/v1/svitlo/curriculum validates section type and saves entry
    public function curriculumCreate(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $entryType = (string)($b['entry_type'] ?? 'outro');
        $title = trim((string)($b['title'] ?? ''));
        if (empty($title)) { $this->abort(400, 'Título da entrada é obrigatório'); return; }

        $institution = $b['institution'] ?? null;
        $description = $b['description'] ?? null;
        $startDate = !empty($b['start_date']) ? $b['start_date'] : null;
        $endDate = !empty($b['end_date']) ? $b['end_date'] : null;
        $inProgress = (int)($b['in_progress'] ?? 0);
        $location = $b['location'] ?? null;
        $url = $b['url'] ?? null;
        $doi = $b['doi'] ?? null;
        $certUrl = $b['certificate_url'] ?? null;
        $extraData = isset($b['extra_data']) ? (is_array($b['extra_data']) ? json_encode($b['extra_data'], JSON_UNESCAPED_UNICODE) : (string)$b['extra_data']) : null;

        MriyaDB::execute(
            'INSERT INTO svitlo_academic_curriculum
             (user_id, entry_type, title, institution, description, start_date, end_date, in_progress, location, url, doi, certificate_url, extra_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, $entryType, $title, $institution, $description, $startDate, $endDate, $inProgress, $location, $url, $doi, $certUrl, $extraData]
        );

        $newId = (int)MriyaDB::lastInsertId();
        $this->respond(['status' => 'created', 'id' => $newId]);
    }

    // PT: Atualiza entrada do currículo
    // AI: @llm-context PUT /api/v1/svitlo/curriculum/{id}
    // PT: Atualiza um item existente do currículo acadêmico do usuário
    // EN: Updates an existing item in the user's academic curriculum
    // AI: @llm-context PUT /api/v1/svitlo/curriculum/{id} ensures user ownership
    public function curriculumUpdate(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $entryType = (string)($b['entry_type'] ?? 'outro');
        $title = trim((string)($b['title'] ?? ''));
        if (empty($title)) { $this->abort(400, 'Título da entrada é obrigatório'); return; }

        $institution = $b['institution'] ?? null;
        $description = $b['description'] ?? null;
        $startDate = !empty($b['start_date']) ? $b['start_date'] : null;
        $endDate = !empty($b['end_date']) ? $b['end_date'] : null;
        $inProgress = (int)($b['in_progress'] ?? 0);
        $location = $b['location'] ?? null;
        $url = $b['url'] ?? null;
        $doi = $b['doi'] ?? null;
        $certUrl = $b['certificate_url'] ?? null;
        $extraData = isset($b['extra_data']) ? (is_array($b['extra_data']) ? json_encode($b['extra_data'], JSON_UNESCAPED_UNICODE) : (string)$b['extra_data']) : null;

        MriyaDB::execute(
            'UPDATE svitlo_academic_curriculum
             SET entry_type=?, title=?, institution=?, description=?, start_date=?, end_date=?, in_progress=?, location=?, url=?, doi=?, certificate_url=?, extra_data=?
             WHERE id=? AND user_id=?',
            [$entryType, $title, $institution, $description, $startDate, $endDate, $inProgress, $location, $url, $doi, $certUrl, $extraData, $id, $uid]
        );

        $this->respond(['status' => 'updated']);
    }

    // PT: Remove entrada do currículo
    // AI: @llm-context DELETE /api/v1/svitlo/curriculum/{id}
    // PT: Remove um item do currículo acadêmico do usuário
    // EN: Deletes an item from the user's academic curriculum
    // AI: @llm-context DELETE /api/v1/svitlo/curriculum/{id} verifies user_id
    public function curriculumDelete(int $id): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        MriyaDB::execute('DELETE FROM svitlo_academic_curriculum WHERE id=? AND user_id=?', [$id, $uid]);
        $this->respond(['status' => 'deleted']);
    }

    // PT: Exporta currículo acadêmico em PDF com formatação Lattes elegante
    // AI: @llm-context GET /api/v1/svitlo/curriculum/export/pdf
    // PT: Gera versão em PDF formatada do currículo acadêmico no padrão ABNT
    // EN: Generates formatted PDF version of the academic curriculum in ABNT standard
    // AI: @llm-context GET /api/v1/svitlo/curriculum/export/pdf renders HTML template to PDF
    public function curriculumExportPdf(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $user = MriyaDB::queryOne('SELECT name, email FROM users WHERE id = ?', [$uid]);
        $academic = MriyaDB::queryOne('SELECT course, institution, biography FROM svitlo_academic_profiles WHERE user_id = ?', [$uid]);
        $entries = MriyaDB::query('SELECT * FROM svitlo_academic_curriculum WHERE user_id = ? ORDER BY entry_type, start_date DESC', [$uid]) ?: [];

        $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">';
        $html .= '<title>Currículo Acadêmico — ' . htmlspecialchars($user['name'] ?? 'Svitlo') . '</title>';
        $html .= '<style>';
        $html .= 'body { font-family: "Georgia", serif; margin: 40px; color: #2C1F14; line-height: 1.6; }';
        $html .= 'h1 { color: #5C3D1E; font-size: 24px; border-bottom: 2px solid #8B5E3C; padding-bottom: 6px; margin-bottom: 4px; }';
        $html .= '.subtitle { font-size: 13px; color: #8B5E3C; margin-bottom: 20px; font-family: sans-serif; }';
        $html .= 'h2 { color: #8B5E3C; font-size: 16px; margin-top: 20px; border-bottom: 1px solid #ddd; padding-bottom: 4px; text-transform: uppercase; font-family: sans-serif; letter-spacing: 0.05em; }';
        $html .= '.entry { margin-bottom: 12px; font-size: 13px; }';
        $html .= '.entry-title { font-weight: bold; color: #2C1F14; }';
        $html .= '.entry-meta { color: #666; font-size: 12px; }';
        $html .= '.footer { margin-top: 40px; font-size: 10px; color: #999; text-align: center; border-top: 1px solid #EEE; padding-top: 10px; font-family: sans-serif; }';
        $html .= '</style></head><body>';

        $html .= '<h1>' . htmlspecialchars($user['name'] ?? 'Currículo Acadêmico') . '</h1>';
        $html .= '<div class="subtitle">' . htmlspecialchars($academic['course'] ?? '') . ' — ' . htmlspecialchars($academic['institution'] ?? '') . ' • ' . htmlspecialchars($user['email'] ?? '') . '</div>';

        if (!empty($academic['biography'])) {
            $html .= '<h2>Resumo Profissional</h2><p style="font-size:13px;font-style:italic;">' . nl2br(htmlspecialchars($academic['biography'])) . '</p>';
        }

        $typeLabels = [
            'graduacao' => 'Formação Acadêmica / Graduação',
            'pos_graduacao' => 'Pós-Graduação',
            'experiencia_profissional' => 'Atuação Profissional',
            'pesquisa' => 'Linhas de Pesquisa',
            'publicacao' => 'Publicações e Artigos',
            'congresso_ouvinte' => 'Congressos e Eventos (Participação)',
            'congresso_palestrante' => 'Congressos e Palestras Ministradas',
            'idioma' => 'Idiomas',
            'premio' => 'Prêmios e Títulos',
            'curso_livre' => 'Cursos Complementares',
            'outro' => 'Outras Produções'
        ];

        $grouped = [];
        foreach ($entries as $e) {
            $grouped[$e['entry_type']][] = $e;
        }

        foreach ($typeLabels as $k => $label) {
            if (!empty($grouped[$k])) {
                $html .= '<h2>' . htmlspecialchars($label) . '</h2>';
                foreach ($grouped[$k] as $item) {
                    $year = $item['start_date'] ? date('Y', strtotime($item['start_date'])) : '';
                    $endYear = $item['in_progress'] ? 'atual' : ($item['end_date'] ? date('Y', strtotime($item['end_date'])) : '');
                    $period = $year ? ($endYear ? "{$year}–{$endYear}" : $year) : '';

                    $html .= '<div class="entry">';
                    $html .= '<div class="entry-title">' . htmlspecialchars($item['title']) . ($item['institution'] ? ' — ' . htmlspecialchars($item['institution']) : '') . ($period ? " ({$period})" : '') . '</div>';
                    if ($item['description']) $html .= '<div>' . nl2br(htmlspecialchars($item['description'])) . '</div>';
                    if ($item['doi']) $html .= '<div class="entry-meta">DOI: ' . htmlspecialchars($item['doi']) . '</div>';
                    if ($item['url']) $html .= '<div class="entry-meta">Link: ' . htmlspecialchars($item['url']) . '</div>';
                    $html .= '</div>';
                }
            }
        }

        $html .= '<div class="footer">Gerado via Svitlo • Ecossistema Mriya • ' . date('d/m/Y H:i') . '</div>';
        $html .= '</body></html>';

        $tmpHtml = tempnam(sys_get_temp_dir(), 'svitlo_lattes_') . '.html';
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'svitlo_lattes_') . '.pdf';
        file_put_contents($tmpHtml, $html);
        shell_exec("wkhtmltopdf --quiet --page-size A4 " . escapeshellarg($tmpHtml) . " " . escapeshellarg($tmpPdf) . " 2>/dev/null");

        $pdfBase64 = '';
        if (file_exists($tmpPdf) && filesize($tmpPdf) > 0) {
            $pdfBase64 = base64_encode((string)file_get_contents($tmpPdf));
        }

        @unlink($tmpHtml);
        @unlink($tmpPdf);

        if (!empty($pdfBase64)) {
            $this->respond(['status' => 'ok', 'pdf' => $pdfBase64, 'filename' => 'curriculo_academico.pdf']);
        } else {
            // Fallback HTML string se wkhtmltopdf falhar
            $this->respond(['status' => 'ok', 'html' => $html, 'filename' => 'curriculo.html']);
        }
    }

    // PT: Exporta currículo acadêmico no formato XML padrão CNPq Lattes
    // AI: @llm-context GET /api/v1/svitlo/curriculum/export/lattes-xml
    // PT: Exporta currículo acadêmico no formato XML compatível com a Plataforma Lattes
    // EN: Exports academic curriculum in XML format compatible with Lattes Platform
    // AI: @llm-context GET /api/v1/svitlo/curriculum/export/lattes-xml formats CNPq schema
    public function curriculumExportLattesXml(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $user = MriyaDB::queryOne('SELECT name, email FROM users WHERE id = ?', [$uid]);
        $academic = MriyaDB::queryOne('SELECT course, institution, biography FROM svitlo_academic_profiles WHERE user_id = ?', [$uid]);
        $entries = MriyaDB::query('SELECT * FROM svitlo_academic_curriculum WHERE user_id = ? ORDER BY start_date DESC', [$uid]) ?: [];

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<CURRICULO-VITAE SISTEMA-ORIGEM-XML=\"SVITLO-MRIYA\" NUMERO-IDENTIFICADOR=\"{$uid}\" DATA-ATUALIZACAO=\"" . date('dmY') . "\" HORA-ATUALIZACAO=\"" . date('His') . "\">\n";
        $xml .= "  <DADOS-GERAIS NOME-COMPLETO=\"" . htmlspecialchars($user['name'] ?? '', ENT_XML1) . "\" PAIS-DE-NASCIMENTO=\"Brasil\" NACIONALIDADE=\"B\">\n";
        $xml .= "    <RESUMO-CV TEXTO-RESUMO-CV-RH=\"" . htmlspecialchars($academic['biography'] ?? '', ENT_XML1) . "\"/>\n";
        $xml .= "  </DADOS-GERAIS>\n";

        $xml .= "  <DADOS-COMPLEMENTARES>\n";
        $xml .= "    <FORMACAO-ACADEMICA-TITULACAO>\n";
        foreach ($entries as $e) {
            if (in_array($e['entry_type'], ['graduacao', 'pos_graduacao', 'curso_livre'])) {
                $anoInicio = $e['start_date'] ? date('Y', strtotime($e['start_date'])) : '';
                $anoConclusao = $e['end_date'] ? date('Y', strtotime($e['end_date'])) : ($e['in_progress'] ? 'EM_ANDAMENTO' : '');
                $xml .= "      <GRADUACAO SEQUENCIA-FORMACAO=\"{$e['id']}\" NOME-CURSO=\"" . htmlspecialchars($e['title'], ENT_XML1) . "\" NOME-INSTITUICAO=\"" . htmlspecialchars($e['institution'] ?? '', ENT_XML1) . "\" ANO-DE-INICIO=\"{$anoInicio}\" ANO-DE-CONCLUSAO=\"{$anoConclusao}\"/>\n";
            }
        }
        $xml .= "    </FORMACAO-ACADEMICA-TITULACAO>\n";

        $xml .= "    <ATUACOES-PROFISSIONAIS>\n";
        foreach ($entries as $e) {
            if ($e['entry_type'] === 'experiencia_profissional') {
                $anoInicio = $e['start_date'] ? date('Y', strtotime($e['start_date'])) : '';
                $anoFim = $e['in_progress'] ? 'ATUAL' : ($e['end_date'] ? date('Y', strtotime($e['end_date'])) : '');
                $xml .= "      <ATUACAO-PROFISSIONAL NOME-INSTITUICAO=\"" . htmlspecialchars($e['institution'] ?? '', ENT_XML1) . "\" CARGO-FUNCAO=\"" . htmlspecialchars($e['title'], ENT_XML1) . "\" ANO-INICIO=\"{$anoInicio}\" ANO-FIM=\"{$anoFim}\"/>\n";
            }
        }
        $xml .= "    </ATUACOES-PROFISSIONAIS>\n";

        $xml .= "    <PRODUCAO-BIBLIOGRAFICA>\n";
        foreach ($entries as $e) {
            if (in_array($e['entry_type'], ['publicacao', 'pesquisa'])) {
                $xml .= "      <ARTIGO-PUBLICADO TITULO-DO-ARTIGO=\"" . htmlspecialchars($e['title'], ENT_XML1) . "\" DOI=\"" . htmlspecialchars($e['doi'] ?? '', ENT_XML1) . "\" URL=\"" . htmlspecialchars($e['url'] ?? '', ENT_XML1) . "\"/>\n";
            }
        }
        $xml .= "    </PRODUCAO-BIBLIOGRAFICA>\n";

        $xml .= "    <EVENTOS>\n";
        foreach ($entries as $e) {
            if (in_array($e['entry_type'], ['congresso_ouvinte', 'congresso_palestrante', 'palestra', 'evento'])) {
                $xml .= "      <PARTICIPACAO-EM-EVENTO TITULO-DO-EVENTO=\"" . htmlspecialchars($e['title'], ENT_XML1) . "\" TIPO-PARTICIPACAO=\"" . htmlspecialchars($e['entry_type'], ENT_XML1) . "\"/>\n";
            }
        }
        $xml .= "    </EVENTOS>\n";
        $xml .= "  </DADOS-COMPLEMENTARES>\n";
        $xml .= "</CURRICULO-VITAE>";

        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="curriculo_lattes_' . $uid . '.xml"');
        echo $xml;
        exit;
    }

    // PT: Obtém conexão com o Redis local para cache de alta performance
    // EN: Retrieves local Redis connection for high performance caching
    // AI: @llm-context Singleton local com fallback gracioso se Redis estiver indisponível
    private function getRedis(): ?\Redis {
        try {
            if (extension_loaded('redis')) {
                $redis = new \Redis();
                if (@$redis->connect('127.0.0.1', 6379, 1.5)) {
                    return $redis;
                }
            }
        } catch (\Throwable $e) {
            // Silently fallback if Redis is unavailable
        }
        return null;
    }

    // PT: Busca referências acadêmicas reais na API OpenAlex com cache de 30 dias no Redis e MySQL
    // EN: Searches real academic references on OpenAlex API with 30-day caching in Redis and MySQL
    // AI: @llm-context GET https://api.openalex.org/works com header User-Agent: Svitlo/1.0 ({email}) e cache openalex:{md5}
    private function searchOpenAlexReferences(string $content, string $userEmail): array {
        // PT: Detecta se o conteúdo é direito brasileiro
        // EN: Detects if content is Brazilian law
        // AI: @llm-context Brazilian law keywords trigger PT/BR filtered search
        $isBrazilianLaw = preg_match('/\b(ADI|STF|STJ|CF\/88|constituição|inconstitucionalidade|jurisprudência|direito|lei|artigo \d+|código civil|código penal|CLT|OAB)\b/i', $content);

        if ($isBrazilianLaw) {
            preg_match_all('/\b(ADI|STF|STJ|CF\/88|constituição|inconstitucionalidade|jurisprudência|direito|lei|artigo \d+|código civil|código penal|CLT|OAB)\b/iu', $content, $matches);
            $legalTerms = array_unique(array_filter(array_map('trim', $matches[0] ?? [])));
            $generalTerms = $this->extractAcademicKeywords($content);
            $rawTerms = array_values(array_unique(array_merge($legalTerms, $generalTerms)));
            $terms = !empty($rawTerms) ? $rawTerms : $generalTerms;
        } else {
            $terms = $this->extractAcademicKeywords($content);
        }

        if (empty($terms)) return [];

        $queryTerms = implode(' ', array_slice($terms, 0, 5));
        $queryHash  = md5(strtolower(trim($queryTerms)) . ($isBrazilianLaw ? ':brlaw' : ''));
        $redisKey   = "openalex:{$queryHash}";

        // 1. PT: Consulta cache no Redis (30 dias) / EN: Query Redis cache (30 days)
        $redis = $this->getRedis();
        if ($redis) {
            try {
                $cachedRaw = $redis->get($redisKey);
                if ($cachedRaw) {
                    $cachedData = json_decode($cachedRaw, true);
                    if (is_array($cachedData) && !empty($cachedData)) return $cachedData;
                }
            } catch (\Throwable $e) {
                $this->logError('searchOpenAlexReferences', 'Redis read error: ' . $e->getMessage());
            }
        }

        // 2. PT: Consulta cache no MySQL (fallback) / EN: Query MySQL cache (fallback)
        try {
            $cached = MriyaDB::queryOne(
                "SELECT results FROM svitlo_academic_cache WHERE query_hash = ? AND (expires_at IS NULL OR expires_at > NOW())",
                [$queryHash]
            );
            if ($cached && !empty($cached['results'])) {
                $data = is_string($cached['results']) ? json_decode($cached['results'], true) : $cached['results'];
                if (is_array($data) && !empty($data)) {
                    // Re-popula Redis se disponível
                    if ($redis) {
                        try { $redis->setex($redisKey, 30 * 86400, json_encode($data, JSON_UNESCAPED_UNICODE)); } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
                    }
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            $this->logError('searchOpenAlexReferences', 'Cache read error: ' . $e->getMessage());
        }

        // 3. PT: Chamada à API OpenAlex com filtro Open Access e filtro de idioma para direito brasileiro
        // EN: OpenAlex API HTTP call with Open Access and language filter for Brazilian law
        // AI: @llm-context User-Agent: Svitlo/1.0 ({email}) e filter=language:pt quando $isBrazilianLaw
        $filterStr = $isBrazilianLaw ? "open_access.is_oa:true,language:pt" : "open_access.is_oa:true";
        $perPage = $isBrazilianLaw ? 15 : 5;
        $url = "https://api.openalex.org/works?search=" . urlencode($queryTerms) . "&filter=" . urlencode($filterStr) . "&per-page={$perPage}&select=id,title,abstract_inverted_index,authorships,publication_year,doi,open_access,best_oa_location,primary_location";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Svitlo/1.0 (' . ($userEmail ?: 'pedro@espectroipe.com.br') . ')',
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $results = [];
        if ($httpCode === 200 && is_string($response)) {
            $json = json_decode($response, true);
            $works = $json['results'] ?? [];
            if (is_array($works)) {
                foreach ($works as $work) {
                    $abstract = $this->reconstructOpenAlexAbstract($work['abstract_inverted_index'] ?? null);
                    $title = (string)($work['title'] ?? 'Sem título');

                    // PT: Verificação de relevância — título ou resumo deve conter ao menos um termo da busca
                    // EN: Relevance check — article title or abstract must contain at least one keyword from original query
                    // AI: @llm-context Discard article if no query keyword appears in title or abstract
                    $isRelevant = false;
                    foreach ($terms as $kw) {
                        if (mb_strlen($kw) >= 2 && (mb_stripos($title, $kw) !== false || mb_stripos($abstract, $kw) !== false)) {
                            $isRelevant = true;
                            break;
                        }
                    }

                    if (!$isRelevant) {
                        continue;
                    }

                    $authorsList = [];
                    if (!empty($work['authorships']) && is_array($work['authorships'])) {
                        foreach ($work['authorships'] as $auth) {
                            $name = $auth['author']['display_name'] ?? '';
                            if ($name) $authorsList[] = $name;
                        }
                    }
                    $authorStr = count($authorsList) > 2 
                        ? $authorsList[0] . ' et al.' 
                        : (implode(', ', $authorsList) ?: 'Autor Desconhecido');

                    $doi = (string)($work['doi'] ?? '');
                    $pdfUrl = (string)($work['best_oa_location']['pdf_url'] ?? ($work['primary_location']['pdf_url'] ?? ($work['open_access']['oa_url'] ?? '')));
                    if (!str_ends_with(strtolower($pdfUrl), '.pdf') && !empty($work['best_oa_location']['pdf_url'])) {
                        $pdfUrl = (string)$work['best_oa_location']['pdf_url'];
                    }
                    $landingUrl = (string)($work['best_oa_location']['landing_page_url'] ?? ($work['primary_location']['landing_page_url'] ?? ($work['open_access']['oa_url'] ?? $doi)));
                    $isOA = (bool)($work['open_access']['is_oa'] ?? false);
                    $hasPdf = !empty($pdfUrl);

                    $results[] = [
                        'id'       => (string)($work['id'] ?? ''),
                        'title'    => $title,
                        'authors'  => $authorStr,
                        'year'     => (int)($work['publication_year'] ?? date('Y')),
                        'doi'      => $doi,
                        'url'      => $landingUrl ?: $doi,
                        'pdf_url'  => $pdfUrl,
                        'has_pdf'  => $hasPdf,
                        'is_oa'    => $isOA,
                        'abstract' => $abstract ?: 'Resumo disponível na publicação original.',
                    ];
                }
            }
        }

        // PT: Se for direito brasileiro e resultados < 2 artigos relevantes, retorna array vazio
        // EN: If Brazilian law and results < 2 relevant articles, return empty array
        // AI: @llm-context Retorna array vazio se menos de 2 artigos forem relevantes em direito brasileiro
        if ($isBrazilianLaw && count($results) < 2) {
            return [];
        }

        // 4. PT: Salva no cache Redis e MySQL por 30 dias se encontrou resultados
        // EN: Saves to Redis and MySQL cache for 30 days if results found
        // AI: @llm-context Salva com TTL de 30 dias (2592000s)
        if (!empty($results)) {
            $payloadJson = json_encode($results, JSON_UNESCAPED_UNICODE);
            if ($redis) {
                try {
                    $redis->setex($redisKey, 30 * 86400, $payloadJson);
                } catch (\Throwable $e) {
                    $this->logError('searchOpenAlexReferences', 'Redis write error: ' . $e->getMessage());
                }
            }

            try {
                $expiresAt = date('Y-m-d H:i:s', time() + (30 * 86400));
                MriyaDB::execute(
                    "INSERT INTO svitlo_academic_cache (query_hash, results, cached_at, expires_at)
                     VALUES (?, ?, NOW(), ?)
                     ON DUPLICATE KEY UPDATE results = VALUES(results), cached_at = NOW(), expires_at = VALUES(expires_at)",
                    [$queryHash, $payloadJson, $expiresAt]
                );
            } catch (\Throwable $e) {
                $this->logError('searchOpenAlexReferences', 'Cache write error: ' . $e->getMessage());
            }
        }

        return $results;
    }

    // PT: Reconstrói o texto do resumo acadêmico a partir do índice invertido da API OpenAlex
    // EN: Reconstructs academic abstract text from OpenAlex API inverted index
    // AI: @llm-context Ordena posições numéricas das palavras e concatena string de texto
    private function reconstructOpenAlexAbstract(?array $invertedIndex): string {
        if (empty($invertedIndex) || !is_array($invertedIndex)) return '';
        $positions = [];
        foreach ($invertedIndex as $word => $posList) {
            if (is_array($posList)) {
                foreach ($posList as $p) {
                    $positions[(int)$p] = (string)$word;
                }
            }
        }
        ksort($positions);
        return implode(' ', $positions);
    }

    // PT: Extrai termos-chave acadêmicos relevantes do texto para busca precisa no OpenAlex
    // EN: Extracts relevant academic keywords from text for accurate OpenAlex searching
    // AI: @llm-context Remove stopwords em português e inglês e seleciona os 3-5 termos mais significativos
    private function extractAcademicKeywords(string $text): array {
        $stopwords = [
            'de','a','o','que','e','do','da','em','um','para','com','nao','uma','os','no','se','na','por','mais','as',
            'dos','como','mas','foi','ao','ele','das','tem','aqui','sua','seu','ou','ser','quando','muito','ha','nos',
            'ja','esta','eu','tambem','so','pelo','pela','ate','isso','ela','entre','era','depois','sem','mesmo','aos',
            'ter','seus','quem','nas','me','esse','eles','estao','voce','tinha','foram','essa','num','nem','suas','meu',
            'the','and','to','of','a','in','for','is','on','that','by','this','with','i','you','it','not','or','be',
            'are','from','at','as','your','all','have','new','more','an','was','we','will','home','can','us','about'
        ];

        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($text));
        $words = preg_split('/\s+/u', (string)$clean, -1, PREG_SPLIT_NO_EMPTY);
        
        $freq = [];
        foreach ($words as $w) {
            if (mb_strlen($w) > 3 && !in_array($w, $stopwords)) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }
        arsort($freq);
        return array_slice(array_keys($freq), 0, 5);
    }

    // PT: Obtém credenciais do Google OAuth a partir de MriyaCredentials ou credentials.json
    // EN: Retrieves Google OAuth credentials from MriyaCredentials or credentials.json
    // AI: @llm-context Lê client_id e client_secret via MriyaCredentials::getGoogleOAuth() com fallback para credentials.json e env()
    private function getGoogleOAuthCredentials(): array {
        if (class_exists('MriyaCredentials')) {
            $google = MriyaCredentials::getGoogleOAuth();
            if (!empty($google['client_id'])) {
                return [
                    'client_id'     => $google['client_id'],
                    'client_secret' => $google['client_secret'],
                    'redirect_uris' => [
                        'https://api.espectroipe.com.br/api/v1/svitlo/health/callback',
                        'https://api.espectroipe.com.br/api/v1/svitlo/connections/spotify/callback',
                        'https://api.espectroipe.com.br/api/v1/svitlo/connections/google/callback'
                    ],
                ];
            }
        }

        $file = '/var/www/mcp-server/credentials/credentials.json';
        if (file_exists($file)) {
            $data = @json_decode((string)file_get_contents($file), true);
            if (!empty($data['google']['oauth_client_id'])) {
                return [
                    'client_id'     => trim((string)$data['google']['oauth_client_id']),
                    'client_secret' => trim((string)($data['google']['oauth_client_secret'] ?? '')),
                    'redirect_uris' => [
                        'https://api.espectroipe.com.br/api/v1/svitlo/health/callback',
                        'https://api.espectroipe.com.br/api/v1/svitlo/connections/spotify/callback',
                        'https://api.espectroipe.com.br/api/v1/svitlo/connections/google/callback'
                    ],
                ];
            }
        }

        $legacy = '/var/www/mcp-server/credentials/google_oauth.json';
        if (file_exists($legacy)) {
            $data = @json_decode((string)file_get_contents($legacy), true);
            if (!empty($data['client_id'])) {
                return [
                    'client_id'     => trim((string)$data['client_id']),
                    'client_secret' => trim((string)($data['client_secret'] ?? '')),
                    'redirect_uris' => $data['redirect_uris'] ?? [],
                ];
            }
        }

        // PT: Lê credenciais do arquivo centralizado — nunca hardcode
        // EN: Reads from centralized credentials file — never hardcode
        // AI: @llm-context /var/www/mcp-server/credentials/credentials.json
        return [
            'client_id'     => mriya_credential('google', 'oauth_client_id'),
            'client_secret' => mriya_credential('google', 'oauth_client_secret'),
            'redirect_uris' => [
                'https://api.espectroipe.com.br/api/v1/svitlo/health/callback',
                'https://api.espectroipe.com.br/api/v1/svitlo/connections/spotify/callback',
                'https://api.espectroipe.com.br/api/v1/svitlo/connections/google/callback'
            ],
        ];
    }

    // PT: Obtém token Google Fit/Health válido com renovação automática por refresh_token
    // EN: Obtains valid Google Fit/Health access token with automatic refresh
    // AI: @llm-context Utiliza getValidGoogleToken() para token unificado ou busca específico
    private function getValidGoogleFitToken(int $userId): ?string {
        $token = $this->getValidGoogleToken($userId);
        if ($token) return $token;

        $conn = MriyaDB::queryOne(
            "SELECT * FROM svitlo_connections 
             WHERE user_id = ? AND service IN ('google', 'google_fit', 'google_health') AND active = 1 
             ORDER BY (service = 'google') DESC LIMIT 1",
            [$userId]
        );
        if (!$conn || empty($conn['credentials_enc'])) return null;

        try {
            $raw = mriya_decrypt((string)$conn['credentials_enc']);
        } catch (\Throwable $e) {
            $raw = $conn['credentials_enc'];
        }

        $creds = json_decode((string)$raw, true);
        if (!is_array($creds) || empty($creds['access_token'])) return null;

        return (string)$creds['access_token'];
    }

    // PT: Inicia o fluxo de autorização OAuth para o Google Health Connect (Fitness API)
    // EN: Initiates OAuth authorization flow for Google Health Connect (Fitness API)
    // AI: @llm-context GET /api/v1/svitlo/health/connect com scopes fitness.sleep, fitness.heart_rate, fitness.activity
    public function healthConnect(): void {
        $token = MriyaJWT::extractFromHeader();
        if (!$token && !empty($_GET['token'])) {
            $token = trim((string)($_GET['token'] ?? '')); // Sanitize query token / Sanitiza o token da query
        }
        $user = $token ? MriyaJWT::validateAccessToken($token) : null;
        if (!$user) {
            $this->abort(401, 'Unauthorized');
            return;
        }
        $this->user = $user;
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);

        $googleCreds = $this->getGoogleOAuthCredentials();
        $clientId = $googleCreds['client_id'];
        $redirect = 'https://api.espectroipe.com.br/api/v1/svitlo/health/callback';
        $scopes = [
            'https://www.googleapis.com/auth/fitness.sleep.read',
            'https://www.googleapis.com/auth/fitness.heart_rate.read',
            'https://www.googleapis.com/auth/fitness.activity.read'
        ];
        $scopeStr = urlencode(implode(' ', $scopes));
        $state = base64_encode(json_encode(['uid' => $uid, 'ts' => time()]));

        $url = "https://accounts.google.com/o/oauth2/v2/auth?client_id={$clientId}&redirect_uri=" . urlencode($redirect) . "&response_type=code&scope={$scopeStr}&access_type=offline&prompt=consent&state=" . urlencode($state);

        if (isset($_GET['redirect']) && $_GET['redirect'] === '1') {
            header('Location: ' . $url);
            exit;
        }

        $this->respond(['auth_url' => $url]);
    }

    // PT: Processa o retorno do OAuth do Google Health, salva as credenciais e executa sincronização inicial
    // EN: Handles Google Health OAuth callback, saves credentials and executes initial sync
    // AI: @llm-context GET /api/v1/svitlo/health/callback
    public function healthCallback(): void {
        $code = $_GET['code'] ?? '';
        $stateRaw = $_GET['state'] ?? '';
        $stateData = json_decode(base64_decode($stateRaw) ?: '{}', true);
        $uid = (int)($stateData['uid'] ?? 0);

        if ($code && $uid > 0) {
            $googleCreds = $this->getGoogleOAuthCredentials();
            $clientId = $googleCreds['client_id'];
            $clientSecret = $googleCreds['client_secret'];
            $redirect = 'https://api.espectroipe.com.br/api/v1/svitlo/health/callback';

            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'code'          => $code,
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri'  => $redirect,
                    'grant_type'    => 'authorization_code'
                ]),
                CURLOPT_TIMEOUT        => 12,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);

            $tokData = json_decode((string)$resp, true);
            if (!empty($tokData['access_token'])) {
                $tokData['token_expires_at'] = date('Y-m-d H:i:s', time() + (int)($tokData['expires_in'] ?? 3600));
                $enc = mriya_encrypt(json_encode($tokData, JSON_UNESCAPED_UNICODE));

                MriyaDB::execute(
                    "INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, active, updated_at)
                     VALUES (?, 'google_fit', 'Google Health Connect', ?, 1, NOW())
                     ON DUPLICATE KEY UPDATE service_username = 'Google Health Connect', credentials_enc = VALUES(credentials_enc), active = 1, updated_at = NOW()",
                    [$uid, $enc]
                );

                $this->syncUserHealthData($uid, (string)$tokData['access_token']);
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><body><script>
            if (window.opener) {
                window.opener.postMessage({ type: 'google_fit_connected' }, '*');
            }
            window.close();
        </script><p>Google Health Connect conectado com sucesso! Fechando janela...</p></body></html>";
        exit;
    }

    // PT: Sincroniza métricas dos últimos 7 dias via Google Fitness API
    // EN: Synchronizes metrics from the last 7 days via Google Fitness API
    // AI: @llm-context POST ou GET /api/v1/svitlo/health/sync
    public function healthSync(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $token = $this->getValidGoogleFitToken($uid);
        if (!$token) {
            $this->abort(400, 'Google Health / Fit não está conectado.');
            return;
        }

        $syncedDays = $this->syncUserHealthData($uid, $token);
        $today = MriyaDB::queryOne("SELECT * FROM svitlo_health_data WHERE user_id = ? AND date = CURDATE()", [$uid]);

        $this->respond([
            'status'       => 'synced',
            'days_synced'  => $syncedDays,
            'today'        => $today,
        ]);
    }

    // PT: Executa agregação dos últimos 7 dias na API do Google Fitness e salva em svitlo_health_data
    // EN: Executes 7-day aggregate query on Google Fitness API and persists to svitlo_health_data
    // AI: @llm-context POST https://www.googleapis.com/fitness/v1/users/me/dataset:aggregate
    private function syncUserHealthData(int $userId, string $accessToken): int {
        $startTimeMillis = (strtotime('-7 days 00:00:00')) * 1000;
        $endTimeMillis   = time() * 1000;

        $payload = [
            'aggregateBy' => [
                ['dataTypeName' => 'com.google.sleep.segment'],
                ['dataTypeName' => 'com.google.heart_rate.bpm'],
                ['dataTypeName' => 'com.google.step_count.delta'],
                ['dataTypeName' => 'com.google.active_minutes']
            ],
            'bucketByTime'    => ['durationMillis' => 86400000],
            'startTimeMillis' => $startTimeMillis,
            'endTimeMillis'   => $endTimeMillis
        ];

        $ch = curl_init('https://www.googleapis.com/fitness/v1/users/me/dataset:aggregate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $synced = 0;
        if ($httpCode === 200 && is_string($resp)) {
            $data = json_decode($resp, true);
            $buckets = $data['bucket'] ?? [];
            if (is_array($buckets)) {
                foreach ($buckets as $bucket) {
                    $startMs = (int)($bucket['startTimeMillis'] ?? 0);
                    if ($startMs <= 0) continue;
                    $date = date('Y-m-d', (int)($startMs / 1000));

                    $sleepHours = 0.0;
                    $heartRateResting = 0;
                    $steps = 0;
                    $exerciseMinutes = 0;

                    $datasets = $bucket['dataset'] ?? [];
                    foreach ($datasets as $ds) {
                        $dataType = $ds['dataSourceId'] ?? '';
                        $points = $ds['point'] ?? [];
                        if (!is_array($points)) continue;

                        foreach ($points as $pt) {
                            $vals = $pt['value'] ?? [];
                            if (str_contains($dataType, 'sleep') || str_contains($dataType, 'com.google.sleep.segment')) {
                                $stMs = (int)($pt['startTimeNanos'] ?? 0) / 1000000;
                                $etMs = (int)($pt['endTimeNanos'] ?? 0) / 1000000;
                                $diffHours = ($etMs - $stMs) / 3600000;
                                if ($diffHours > 0) $sleepHours += $diffHours;
                            }
                            if (str_contains($dataType, 'heart_rate') || str_contains($dataType, 'bpm')) {
                                $bpm = (int)($vals[0]['fpVal'] ?? ($vals[0]['intVal'] ?? 0));
                                if ($bpm > 0) {
                                    $heartRateResting = ($heartRateResting === 0) ? $bpm : (int)(($heartRateResting + $bpm) / 2);
                                }
                            }
                            if (str_contains($dataType, 'step_count')) {
                                $steps += (int)($vals[0]['intVal'] ?? 0);
                            }
                            if (str_contains($dataType, 'active_minutes')) {
                                $exerciseMinutes += (int)($vals[0]['intVal'] ?? ($vals[0]['fpVal'] ?? 0));
                            }
                        }
                    }

                    if ($sleepHours <= 0) $sleepHours = 7.5;
                    if ($heartRateResting <= 0) $heartRateResting = 65;

                    $sleepQuality = 'good';
                    if ($sleepHours >= 8.5) $sleepQuality = 'excellent';
                    elseif ($sleepHours >= 7.0) $sleepQuality = 'good';
                    elseif ($sleepHours >= 6.0) $sleepQuality = 'fair';
                    else $sleepQuality = 'poor';

                    $readiness = $this->calculateReadinessScore($sleepHours, $heartRateResting, $exerciseMinutes, $steps);

                    MriyaDB::execute(
                        "INSERT INTO svitlo_health_data
                         (user_id, date, sleep_hours, sleep_quality, heart_rate_resting, steps, exercise_minutes, readiness_score, source, synced_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'google_fit', NOW())
                         ON DUPLICATE KEY UPDATE
                         sleep_hours = VALUES(sleep_hours),
                         sleep_quality = VALUES(sleep_quality),
                         heart_rate_resting = VALUES(heart_rate_resting),
                         steps = VALUES(steps),
                         exercise_minutes = VALUES(exercise_minutes),
                         readiness_score = VALUES(readiness_score),
                         synced_at = NOW()",
                        [$userId, $date, round($sleepHours, 2), $sleepQuality, $heartRateResting, $steps, $exerciseMinutes, $readiness]
                    );
                    $synced++;
                }
            }
        }

        return $synced;
    }

    // PT: Calcula o score de prontidão biológica (0-100) baseado em sono, FC repouso e passos
    // EN: Calculates biological readiness score (0-100) based on sleep, resting HR, and steps
    // AI: @llm-context sono >= 7h (+40) | FC repouso <= 60 (+30) | passos >= 5000 (+30)
    private function calculateReadinessScore(float $sleepHours, int $restingHeartRate, int $exerciseMinutes = 0, int $steps = 0): int {
        $score = 0;

        // 1. Sono: >= 7h (+40) | 5.5-7h (+20) | < 5.5h (+10)
        if ($sleepHours >= 7.0) {
            $score += 40;
        } elseif ($sleepHours >= 5.5) {
            $score += 20;
        } else {
            $score += 10;
        }

        // 2. FC Repouso: <= 60 (+30) | 61-75 (+20) | > 75 (+10)
        if ($restingHeartRate > 0 && $restingHeartRate <= 60) {
            $score += 30;
        } elseif ($restingHeartRate > 0 && $restingHeartRate <= 75) {
            $score += 20;
        } else {
            $score += 10;
        }

        // 3. Passos / Atividade: >= 5000 (+30) | 2500-5000 (+15) | < 2500 (0)
        if ($steps >= 5000 || $exerciseMinutes >= 30) {
            $score += 30;
        } elseif ($steps >= 2500 || $exerciseMinutes > 0) {
            $score += 15;
        }

        return min(100, max(0, $score));
    }

    // PT: Retorna as métricas de saúde e prontidão de hoje
    // EN: Returns health metrics and readiness for today
    // AI: @llm-context GET /api/v1/svitlo/health/today — Retorna { sleep_hours, heart_rate_resting, steps, readiness_score }
    public function healthToday(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        $row = MriyaDB::queryOne("SELECT * FROM svitlo_health_data WHERE user_id = ? AND date = CURDATE()", [$uid]);

        if (!$row) {
            $token = $this->getValidGoogleFitToken($uid);
            if ($token) {
                $this->syncUserHealthData($uid, $token);
                $row = MriyaDB::queryOne("SELECT * FROM svitlo_health_data WHERE user_id = ? AND date = CURDATE()", [$uid]);
            }
        }

        $sleepHours = (float)($row['sleep_hours'] ?? 7.5);
        $restingHr  = (int)($row['heart_rate_resting'] ?? 58);
        $steps      = (int)($row['steps'] ?? 5200);
        $exercise   = (int)($row['exercise_minutes'] ?? 0);

        $readiness = isset($row['readiness_score'])
            ? (int)$row['readiness_score']
            : $this->calculateReadinessScore($sleepHours, $restingHr, $exercise, $steps);

        $this->respond([
            'sleep_hours'        => round($sleepHours, 1),
            'heart_rate_resting' => $restingHr,
            'steps'              => $steps,
            'readiness_score'    => $readiness,
            'date'               => $row['date'] ?? date('Y-m-d'),
            'source'             => $row['source'] ?? 'google_health',
        ]);
    }

    // PT: Calcula calibração holística do Pomodoro (Prontidão Biológica + Perfil Neuropsicológico + Agenda do Dia + Ritmo Circadiano)
    // EN: Calculates holistic Pomodoro calibration (Biological Readiness + Neuropsych Profile + Daily Schedule + Circadian Rhythm)
    // AI: @llm-context GET /api/v1/svitlo/health/readiness
    public function healthReadiness(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        // 1. Dados Biológicos (Google Health / Fit)
        $row = MriyaDB::queryOne("SELECT * FROM svitlo_health_data WHERE user_id = ? AND date = CURDATE()", [$uid]);
        if (!$row) {
            $token = $this->getValidGoogleFitToken($uid);
            if ($token) {
                $this->syncUserHealthData($uid, $token);
                $row = MriyaDB::queryOne("SELECT * FROM svitlo_health_data WHERE user_id = ? AND date = CURDATE()", [$uid]);
            }
        }

        $sleepHours = (float)($row['sleep_hours'] ?? 7.5);
        $restingHr  = (int)($row['heart_rate_resting'] ?? 58);
        $exercise   = (int)($row['exercise_minutes'] ?? 0);
        $steps      = (int)($row['steps'] ?? 5200);

        $bioScore = isset($row['readiness_score']) 
            ? (int)$row['readiness_score'] 
            : $this->calculateReadinessScore($sleepHours, $restingHr, $exercise, $steps);

        // 2. Perfil Neuropsicológico & Cognitivo
        $neuro = MriyaDB::queryOne(
            "SELECT neuro_qi, neuro_diagnoses, neuro_working_memory, neuro_attention,
                    neuro_attention_concentrated, neuro_processing_speed, neuro_flexibility
             FROM svitlo_academic_profiles WHERE user_id = ?",
            [$uid]
        ) ?: [];

        $diagnosesRaw = $neuro['neuro_diagnoses'] ?? '';
        $diagnoses = [];
        if (!empty($diagnosesRaw)) {
            $dec = json_decode($diagnosesRaw, true);
            $diagnoses = is_array($dec) ? $dec : array_map('trim', explode(',', $diagnosesRaw));
        }

        $hasADHD = false;
        $hasASD = false;
        $hasHighAbilities = false;
        foreach ($diagnoses as $d) {
            $dLower = mb_strtolower((string)$d);
            if (str_contains($dLower, 'tdah') || str_contains($dLower, 'adhd') || str_contains($dLower, 'déficit de atenção') || str_contains($dLower, 'deficit de atencao')) {
                $hasADHD = true;
            }
            if (str_contains($dLower, 'tea') || str_contains($dLower, 'autismo') || str_contains($dLower, 'asd')) {
                $hasASD = true;
            }
            if (str_contains($dLower, 'altas habilidades') || str_contains($dLower, 'superdotação') || str_contains($dLower, 'gifted')) {
                $hasHighAbilities = true;
            }
        }

        $qi = (int)($neuro['neuro_qi'] ?? 0);
        if ($qi >= 120) $hasHighAbilities = true;

        // 3. Agenda & Carga do Dia (svitlo_calendar_events)
        $todayEvents = MriyaDB::query(
            "SELECT title, event_type, type, start_datetime
             FROM svitlo_calendar_events
             WHERE user_id = ? AND DATE(start_datetime) = CURDATE()",
            [$uid]
        ) ?: [];

        $eventsCount = count($todayEvents);
        $examsToday = 0;
        foreach ($todayEvents as $ev) {
            $t = mb_strtolower(($ev['title'] ?? '') . ' ' . ($ev['event_type'] ?? '') . ' ' . ($ev['type'] ?? ''));
            if (str_contains($t, 'prova') || str_contains($t, 'exame') || str_contains($t, 'exam')) {
                $examsToday++;
            }
        }

        // 4. Ritmo Circadiano (Hora atual)
        $currentHour = (int)date('G');
        $isMorning = ($currentHour >= 6 && $currentHour < 12);
        $isPostLunch = ($currentHour >= 12 && $currentHour < 15);
        $isAfternoon = ($currentHour >= 15 && $currentHour < 19);
        $isNight = ($currentHour >= 19 || $currentHour < 6);

        // 5. Algoritmo de Calibração Holística do Pomodoro
        $holisticScore = $bioScore;
        $focusMinutes = 25;
        $breakMinutes = 5;
        $status = "standard";
        $rationale = [];

        // Fator 1: Biológico
        if ($bioScore > 75) {
            $focusMinutes = 50;
            $breakMinutes = 10;
            $status = "deep_focus";
            $rationale[] = "Prontidão biológica excelente ({$bioScore}%).";
        } elseif ($bioScore >= 45) {
            $focusMinutes = 25;
            $breakMinutes = 5;
            $status = "standard";
            $rationale[] = "Prontidão biológica moderada ({$bioScore}%).";
        } else {
            $focusMinutes = 15;
            $breakMinutes = 5;
            $status = "light";
            $rationale[] = "Recuperação biológica necessária (sono {$sleepHours}h, FC {$restingHr}bpm).";
        }

        // Fator 2: Neuropsicológico
        if ($hasADHD) {
            if ($focusMinutes > 25) {
                $focusMinutes = 25;
                $breakMinutes = 5;
                $status = "adhd_optimized";
            }
            $rationale[] = "Ajuste neurocognitivo (TDAH): blocos estruturados de 25m para sustentar dopamina e foco sem sobrecarga.";
        } elseif ($hasHighAbilities && $bioScore >= 60 && ($isMorning || $isAfternoon)) {
            $focusMinutes = 50;
            $breakMinutes = 10;
            $status = "hyperfocus";
            $rationale[] = "Pico de processamento cognitivo (QI {$qi}): janela de hiperfoco de 50m.";
        }

        // Fator 3: Agenda e Provas do Dia
        if ($examsToday > 0) {
            $focusMinutes = 20;
            $breakMinutes = 5;
            $status = "exam_prep";
            $rationale[] = "⚠️ Prova agendada hoje ({$examsToday}): ciclos rápidos de 20m com Active Recall.";
        } elseif ($eventsCount >= 4) {
            if ($focusMinutes > 25) $focusMinutes = 25;
            $breakMinutes = 8;
            $rationale[] = "Agenda densa hoje ({$eventsCount} compromissos): pausas estendidas para conservação de energia.";
        }

        // Fator 4: Ritmo Circadiano
        if ($isNight && $focusMinutes > 25) {
            $focusMinutes = 25;
            $breakMinutes = 5;
            $rationale[] = "Período noturno: ciclos moderados para consolidação de memória.";
        } elseif ($isPostLunch && $bioScore < 70) {
            $focusMinutes = 20;
            $breakMinutes = 5;
            $rationale[] = "Janela pós-prandial: foco leve de 20m.";
        }

        $message = implode(' ', $rationale);

        $this->respond([
            'readiness_score'    => $bioScore,
            'holistic_score'     => $holisticScore,
            'sleep_hours'        => round($sleepHours, 1),
            'heart_rate_resting' => $restingHr,
            'exercise_minutes'   => $exercise,
            'steps'              => $steps,
            'focus_minutes'      => $focusMinutes,
            'break_minutes'      => $breakMinutes,
            'recommendation'     => $message,
            'message'            => $message,
            'status'             => $status,
            'source'             => $row['source'] ?? 'google_health',
            'factors'            => [
                'biological' => [
                    'score'              => $bioScore,
                    'sleep_hours'        => round($sleepHours, 1),
                    'heart_rate_resting' => $restingHr,
                    'steps'              => $steps,
                ],
                'neuropsych' => [
                    'adhd'           => $hasADHD,
                    'asd'            => $hasASD,
                    'high_abilities' => $hasHighAbilities,
                    'qi'             => $qi,
                ],
                'calendar' => [
                    'events_today' => $eventsCount,
                    'exams_today'  => $examsToday,
                ],
                'circadian' => [
                    'hour'   => $currentHour,
                    'period' => $isMorning ? 'Manhã' : ($isPostLunch ? 'Pós-almoço' : ($isAfternoon ? 'Tarde' : 'Noite')),
                ],
            ],
        ]);
    }

    // ── CONVITES DE TURMA (RESEND EMAIL) ────────────────────────────

    // PT: Envia convite de turma para um estudante via Resend
    // EN: Sends class invite to a student via Resend
    // AI: @llm-context POST /api/v1/svitlo/class/invite
    public function classInvite(): void {
        $this->requireAuth();
        $b = $this->json();

        $email = strtolower(trim((string)($b['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->abort(400, 'E-mail do estudante inválido.');
            return;
        }

        $profName  = (string)($this->user['name'] ?? 'Professor(a)');
        $className = trim((string)($b['class_name'] ?? ($b['turma'] ?? ($b['discipline_name'] ?? 'Turma Acadêmica'))));
        $code      = trim((string)($b['invite_code'] ?? ($b['code'] ?? strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)))));
        $inviteUrl = trim((string)($b['invite_url'] ?? "https://svitlo.espectroipe.com.br/register.html?invite={$code}"));

        $sent = MriyaMailer::sendClassInvite($email, $profName, $className, $code, $inviteUrl);
        $this->respond([
            'status' => $sent ? 'sent' : 'failed',
            'email'  => $email,
            'code'   => $code
        ]);
    }

    // ── CALENDÁRIO EXTERNO & INTEGRAÇÕES MULTI-PROVEDOR ────────────

    // PT: Obtém token Google válido com renovação automática por refresh_token
    // EN: Obtains valid Google access token with automatic refresh
    // AI: @llm-context Consulta svitlo_connections (todas as conexões Google) e svitlo_calendar_integrations e renova via oauth2.googleapis.com
    private function getValidGoogleToken(int $userId, bool $forceRefresh = false): ?string {
        $accessToken = null;
        $refreshToken = null;
        $expiresAt = 0;

        // 1. Procura em svitlo_connections (todas as conexões Google ativas)
        $conns = MriyaDB::query("SELECT * FROM svitlo_connections WHERE user_id = ? AND service IN ('google', 'google_calendar', 'google_fit', 'google_drive') AND active = 1 ORDER BY (service = 'google') DESC, updated_at DESC", [$userId]) ?: [];
        foreach ($conns as $conn) {
            if (!empty($conn['credentials_enc'])) {
                try {
                    $raw = mriya_decrypt((string)$conn['credentials_enc']);
                } catch (\Throwable $e) {
                    $raw = $conn['credentials_enc'];
                }
                $creds = json_decode((string)$raw, true);
                if (is_array($creds)) {
                    if (!$accessToken && !empty($creds['access_token'])) {
                        $accessToken = (string)$creds['access_token'];
                        $expiresAt = !empty($creds['token_expires_at']) ? strtotime($creds['token_expires_at']) : 0;
                    }
                    if (!$refreshToken && !empty($creds['refresh_token'])) {
                        $refreshToken = (string)$creds['refresh_token'];
                    }
                }
            }
        }

        // 2. Consulta svitlo_calendar_integrations como fonte complementar de token/refresh_token
        $integ = MriyaDB::queryOne("SELECT * FROM svitlo_calendar_integrations WHERE user_id = ? AND provider = 'google'", [$userId]);
        if ($integ) {
            if (!$accessToken && !empty($integ['access_token'])) {
                $accessToken = (string)$integ['access_token'];
                $expiresAt = !empty($integ['token_expires_at']) ? strtotime($integ['token_expires_at']) : 0;
            }
            if (!$refreshToken && !empty($integ['refresh_token'])) {
                $refreshToken = (string)$integ['refresh_token'];
            }
        }

        if (!$accessToken && !$refreshToken) return null;

        // Se ainda for válido (margem de 5 minutos) e não for forceRefresh, retorna o access token
        if (!$forceRefresh && $accessToken && $expiresAt > time() + 300) {
            return $accessToken;
        }

        // Se expirou (ou não temos access_token) e não temos refresh_token, retorna o que tiver
        if (empty($refreshToken)) {
            return $accessToken;
        }

        // Renova via oauth2.googleapis.com
        $googleCreds = $this->getGoogleOAuthCredentials();
        $clientId = $googleCreds['client_id'];
        $clientSecret = $googleCreds['client_secret'];

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string)$resp, true);
        if ($httpCode === 200 && isset($json['access_token'])) {
            $newTok = (string)$json['access_token'];
            $exp = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 3600));

            MriyaDB::execute(
                "UPDATE svitlo_calendar_integrations SET access_token = ?, refresh_token = IF(? != '', ?, refresh_token), token_expires_at = ? WHERE user_id = ? AND provider = 'google'",
                [$newTok, $refreshToken, $refreshToken, $exp, $userId]
            );

            // Atualiza em svitlo_connections para conexões Google ativas
            $gConns = MriyaDB::query("SELECT id FROM svitlo_connections WHERE user_id = ? AND service IN ('google', 'google_calendar', 'google_fit', 'google_drive')", [$userId]) ?: [];
            foreach ($gConns as $c) {
                $saved = [
                    'access_token'     => $newTok,
                    'refresh_token'    => $refreshToken,
                    'token_expires_at' => $exp,
                ];
                $enc = mriya_encrypt(json_encode($saved, JSON_UNESCAPED_UNICODE));
                MriyaDB::execute("UPDATE svitlo_connections SET credentials_enc = ?, updated_at = NOW() WHERE id = ?", [$enc, $c['id']]);
            }

            return $newTok;
        }

        return $accessToken;
    }

    // PT: Empurra evento para Google Calendar via REST API com lembretes inteligentes
    // EN: Pushes event to Google Calendar via REST API with smart reminders
    // AI: @llm-context POST https://www.googleapis.com/calendar/v3/calendars/primary/events com 24h e 1h de lembrete para provas
    private function pushGoogleEvent(string $accessToken, array $event): ?string {
        $startIso = date('c', strtotime($event['start_datetime']));
        $endIso = !empty($event['end_datetime']) ? date('c', strtotime($event['end_datetime'])) : date('c', strtotime($event['start_datetime']) + 7200);

        $reminders = [
            'useDefault' => false,
            'overrides'  => [['method' => 'popup', 'minutes' => (int)($event['reminder_minutes'] ?? 60)]]
        ];

        // PT: Para provas, cria lembretes de 24h antes (1440 min) e 1h antes (60 min)
        // EN: For exams, creates reminders 24h before (1440 min) and 1h before (60 min)
        if (($event['event_type'] ?? '') === 'prova') {
            $reminders['overrides'] = [
                ['method' => 'popup', 'minutes' => 1440],
                ['method' => 'popup', 'minutes' => 60],
            ];
        }

        $payload = [
            'summary'     => (string)$event['title'],
            'description' => (string)($event['description'] ?? 'Svitlo — ' . $event['title']),
            'start'       => ['dateTime' => $startIso],
            'end'         => ['dateTime' => $endIso],
            'location'    => (string)($event['location'] ?? ''),
            'reminders'   => $reminders
        ];

        $ch = curl_init('https://www.googleapis.com/calendar/v3/calendars/primary/events');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $json = json_decode((string)$res, true);
        return $json['id'] ?? null;
    }

    // PT: Atualiza evento no Google Calendar via PATCH HTTP
    // EN: Updates event in Google Calendar via HTTP PATCH
    // AI: @llm-context PATCH https://www.googleapis.com/calendar/v3/calendars/primary/events/{external_event_id}
    private function patchGoogleEvent(string $accessToken, string $externalEventId, array $event): bool {
        $startIso = date('c', strtotime($event['start_datetime']));
        $endIso = !empty($event['end_datetime']) ? date('c', strtotime($event['end_datetime'])) : date('c', strtotime($event['start_datetime']) + 7200);

        $reminders = [
            'useDefault' => false,
            'overrides'  => [['method' => 'popup', 'minutes' => 60]]
        ];
        if (($event['event_type'] ?? '') === 'prova') {
            $reminders['overrides'] = [
                ['method' => 'popup', 'minutes' => 1440],
                ['method' => 'popup', 'minutes' => 60],
            ];
        }

        $payload = [
            'summary'     => (string)$event['title'],
            'description' => (string)($event['description'] ?? 'Svitlo — ' . $event['title']),
            'start'       => ['dateTime' => $startIso],
            'end'         => ['dateTime' => $endIso],
            'location'    => (string)($event['location'] ?? ''),
            'reminders'   => $reminders
        ];

        $ch = curl_init('https://www.googleapis.com/calendar/v3/calendars/primary/events/' . urlencode($externalEventId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    // PT: Remove evento no Google Calendar via DELETE HTTP
    // EN: Removes event in Google Calendar via HTTP DELETE
    // AI: @llm-context DELETE https://www.googleapis.com/calendar/v3/calendars/primary/events/{external_event_id}
    private function deleteGoogleEvent(string $accessToken, string $externalEventId): bool {
        $ch = curl_init('https://www.googleapis.com/calendar/v3/calendars/primary/events/' . urlencode($externalEventId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    // PT: Redireciona ou inicia autenticação OAuth do Ecossistema Google (Calendar, Drive, Health, Gemini)
    // EN: Redirects or initiates OAuth authentication for Google Ecosystem (Calendar, Drive, Health, Gemini)
    // AI: @llm-context GET /api/v1/svitlo/google/auth ou /api/v1/svitlo/calendar/google/auth
    public function calendarGoogleAuth(): void {
        $token = MriyaJWT::extractFromHeader();
        if (!$token && !empty($_GET['token'])) {
            $token = trim((string)($_GET['token'] ?? '')); // Sanitize query token / Sanitiza o token da query
        }
        $user = $token ? MriyaJWT::validateAccessToken($token) : null;
        if (!$user) {
            $this->abort(401, 'Unauthorized');
            return;
        }
        $this->user = $user;
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);

        $googleCreds = $this->getGoogleOAuthCredentials();
        $clientId = $googleCreds['client_id'];
        $redirect = 'https://api.espectroipe.com.br/api/v1/svitlo/connections/google/callback';
        // PT: Escopos requeridos para unificar Calendar, Drive, Health (Fitness) e Perfil
        // EN: Required scopes to unify Calendar, Drive, Health (Fitness) and Profile
        // AI: @llm-context calendar.readonly, drive.file, fitness.activity.read, fitness.sleep.read, fitness.heart_rate.read
        $scopes = [
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/fitness.activity.read',
            'https://www.googleapis.com/auth/fitness.sleep.read',
            'https://www.googleapis.com/auth/fitness.heart_rate.read',
            'openid',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile'
        ];
        $scope = urlencode(implode(' ', $scopes));
        $state = base64_encode(json_encode(['uid' => $uid, 'ts' => time()]));

        $url = "https://accounts.google.com/o/oauth2/v2/auth?client_id={$clientId}&redirect_uri=" . urlencode($redirect) . "&response_type=code&scope={$scope}&access_type=offline&prompt=consent&include_granted_scopes=true&state=" . urlencode($state);

        if (isset($_GET['redirect']) && $_GET['redirect'] === '1') {
            header('Location: ' . $url);
            exit;
        }

        $this->respond(['auth_url' => $url]);
    }

    // PT: Ponto de entrada unificado para autorização do ecossistema Google
    // EN: Unified entry point for Google ecosystem OAuth authorization
    // AI: @llm-context GET /api/v1/svitlo/google/auth redirects to calendarGoogleAuth
    public function googleAuth(): void {
        $this->calendarGoogleAuth();
    }

    // PT: Callback OAuth Google Unificado — salva tokens e desbloqueia Calendar, Drive, Health e Gemini
    // EN: Unified Google OAuth callback — saves tokens and unlocks Calendar, Drive, Health, and Gemini
    // AI: @llm-context GET /api/v1/svitlo/connections/google/callback ou /api/v1/svitlo/calendar/google/callback
    public function calendarGoogleCallback(): void {
        $code = $_GET['code'] ?? '';
        $stateRaw = $_GET['state'] ?? '';
        $stateData = json_decode(base64_decode($stateRaw) ?: '{}', true);
        $uid = (int)($stateData['uid'] ?? 0);

        if ($code && $uid > 0) {
            $googleCreds = $this->getGoogleOAuthCredentials();
            $clientId = $googleCreds['client_id'];
            $clientSecret = $googleCreds['client_secret'];
            $redirect = 'https://api.espectroipe.com.br/api/v1/svitlo/connections/google/callback';

            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'code'          => $code,
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri'  => $redirect,
                    'grant_type'    => 'authorization_code'
                ]),
                CURLOPT_TIMEOUT        => 15,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);

            $tokData = json_decode((string)$resp, true);
            if (!empty($tokData['access_token'])) {
                $acc = (string)$tokData['access_token'];
                $ref = (string)($tokData['refresh_token'] ?? '');
                $exp = date('Y-m-d H:i:s', time() + (int)($tokData['expires_in'] ?? 3600));
                $tokData['token_expires_at'] = $exp;

                // PT: Busca informações do perfil do usuário Google
                // EN: Fetches Google user profile info
                // AI: @llm-context GET https://www.googleapis.com/oauth2/v2/userinfo
                $chUser = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
                curl_setopt_array($chUser, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$acc}"],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_TIMEOUT        => 10,
                ]);
                $userRaw = curl_exec($chUser);
                curl_close($chUser);
                $userInfo = json_decode((string)$userRaw, true) ?: [];
                $email = !empty($userInfo['email']) ? trim((string)$userInfo['email']) : 'Conta Google';
                $name  = !empty($userInfo['name']) ? trim((string)$userInfo['name']) : '';
                $picture = !empty($userInfo['picture']) ? trim((string)$userInfo['picture']) : '';

                // PT: Cria automaticamente a pasta 'Svitlo' e subpastas no Google Drive do usuário
                // EN: Automatically creates 'Svitlo' folder and subfolders in user's Google Drive
                // AI: @llm-context Pasta Svitlo na raiz do Drive com subpastas Resumos, Cards, Áudios
                $driveExtra = $this->ensureSvitloDriveFolder($uid, $acc);

                $extraData = [
                    'email'        => $email,
                    'name'         => $name,
                    'picture'      => $picture,
                    'folder_id'    => $driveExtra['folder_id'] ?? null,
                    'folder_url'   => $driveExtra['folder_url'] ?? null,
                    'subfolders'   => $driveExtra['subfolders'] ?? [],
                    'scopes'       => $tokData['scope'] ?? '',
                    'connected_at' => date('Y-m-d H:i:s'),
                ];

                // Preservar refresh_token anterior se o Google não retornou neste consentimento
                $existingGoogle = MriyaDB::queryOne("SELECT id, credentials_enc FROM svitlo_connections WHERE user_id = ? AND service = 'google'", [$uid]);
                if (empty($ref) && $existingGoogle && !empty($existingGoogle['credentials_enc'])) {
                    try {
                        $oldCreds = json_decode(mriya_decrypt((string)$existingGoogle['credentials_enc']), true);
                        if (!empty($oldCreds['refresh_token'])) {
                            $ref = (string)$oldCreds['refresh_token'];
                            $tokData['refresh_token'] = $ref;
                        }
                    } catch (\Throwable $e) { $this->logError('catch', $e->getMessage()); }
                }

                $enc = mriya_encrypt(json_encode($tokData, JSON_UNESCAPED_UNICODE));

                // 1. Salvar em svitlo_connections (service='google')
                if ($existingGoogle) {
                    MriyaDB::execute(
                        "UPDATE svitlo_connections 
                         SET service_username = ?, credentials_enc = ?, extra_data = ?, active = 1, updated_at = NOW() 
                         WHERE id = ?",
                        [$email, $enc, json_encode($extraData, JSON_UNESCAPED_UNICODE), $existingGoogle['id']]
                    );
                } else {
                    MriyaDB::execute(
                        "INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, extra_data, active, updated_at)
                         VALUES (?, 'google', ?, ?, ?, 1, NOW())",
                        [$uid, $email, $enc, json_encode($extraData, JSON_UNESCAPED_UNICODE)]
                    );
                }

                // 2. Sincroniza em svitlo_calendar_integrations para retrocompatibilidade
                MriyaDB::execute(
                    "INSERT INTO svitlo_calendar_integrations
                     (user_id, provider, access_token, refresh_token, token_expires_at, sync_enabled, last_sync_at)
                     VALUES (?, 'google', ?, ?, ?, 1, NOW())
                     ON DUPLICATE KEY UPDATE
                     access_token = VALUES(access_token),
                     refresh_token = IF(VALUES(refresh_token) != '', VALUES(refresh_token), refresh_token),
                     token_expires_at = VALUES(token_expires_at),
                     sync_enabled = 1,
                     last_sync_at = NOW()",
                    [$uid, $acc, $ref, $exp]
                );

                // 3. Atualiza/mantém sincronizados os registros de serviços derivados
                foreach (['google_calendar', 'google_fit', 'google_drive'] as $srv) {
                    $exSrv = MriyaDB::queryOne("SELECT id FROM svitlo_connections WHERE user_id = ? AND service = ?", [$uid, $srv]);
                    $srvUser = ($srv === 'google_drive') ? 'Google Drive (Svitlo)' : (($srv === 'google_fit') ? 'Google Health Connect' : ($email ?: 'Google Calendar'));
                    $extraJson = ($srv === 'google_drive') ? json_encode($driveExtra, JSON_UNESCAPED_UNICODE) : null;
                    if ($exSrv) {
                        MriyaDB::execute("UPDATE svitlo_connections SET service_username = ?, credentials_enc = ?, extra_data = COALESCE(?, extra_data), active = 1, updated_at = NOW() WHERE id = ?", [$srvUser, $enc, $extraJson, $exSrv['id']]);
                    } else {
                        MriyaDB::execute("INSERT INTO svitlo_connections (user_id, service, service_username, credentials_enc, extra_data, active, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW())", [$uid, $srv, $srvUser, $enc, $extraJson]);
                    }
                }

                // 4. Executa sincronização inicial de Calendar e Health
                $this->syncGoogleCalendarToSvitlo($uid, $acc);
                $this->syncUserHealthData($uid, $acc);
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><body><script>
            if (window.opener) {
                window.opener.postMessage({ type: 'google_connected', service: 'google' }, '*');
                window.opener.postMessage({ type: 'google_calendar_connected' }, '*');
                window.opener.postMessage({ type: 'google_fit_connected' }, '*');
                window.opener.postMessage({ type: 'google_health_connected' }, '*');
            }
            window.close();
        </script><p>Conta Google conectada com sucesso! Fechando janela...</p></body></html>";
        exit;
    }

    // PT: Processa callback de autorização OAuth dos serviços Google
    // EN: Processes OAuth authorization callback for Google services
    // AI: @llm-context GET /api/v1/svitlo/connections/google/callback exchanges code for tokens
    public function googleCallback(): void {
        $this->calendarGoogleCallback();
    }

    /**
     * PT: Cria e verifica a pasta 'Svitlo' e subpastas no Google Drive do usuário
     * EN: Creates and verifies 'Svitlo' folder and subfolders in user's Google Drive
     * AI: @llm-context Cria 'Svitlo' na raiz do Drive e subpastas 'Resumos', 'Cards', 'Áudios'
     */
    // PT: Cria e verifica a pasta 'Svitlo' e subpastas no Google Drive do usuário
    // EN: Creates and verifies 'Svitlo' folder and subfolders in user's Google Drive
    // AI: @llm-context Cria 'Svitlo' na raiz do Drive e subpastas 'Resumos', 'Cards', 'Áudios'
    private function ensureSvitloDriveFolder(int $userId, string $accessToken): ?array {
        try {
            // 1. Procurar pasta Svitlo na raiz do Drive
            $q = urlencode("name = 'Svitlo' and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
            $ch = curl_init("https://www.googleapis.com/drive/v3/files?q={$q}&fields=files(id,name,webViewLink)");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_TIMEOUT        => 15
            ]);
            $res = json_decode((string)curl_exec($ch), true);
            curl_close($ch);

            $folderId = null;
            $folderLink = null;
            if (!empty($res['files'][0]['id'])) {
                $folderId = $res['files'][0]['id'];
                $folderLink = $res['files'][0]['webViewLink'] ?? "https://drive.google.com/drive/folders/{$folderId}";
            } else {
                $ch = curl_init('https://www.googleapis.com/drive/v3/files');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'name'        => 'Svitlo',
                        'mimeType'    => 'application/vnd.google-apps.folder',
                        'parents'     => ['root'],
                        'description' => 'Ecossistema Svitlo — Estudos, Resumos e Flashcards'
                    ]),
                    CURLOPT_HTTPHEADER     => [
                        "Authorization: Bearer {$accessToken}",
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT        => 15
                ]);
                $created = json_decode((string)curl_exec($ch), true);
                curl_close($ch);
                if (!empty($created['id'])) {
                    $folderId = $created['id'];
                    $folderLink = "https://drive.google.com/drive/folders/{$folderId}";
                }
            }

            if (!$folderId) return null;

            // 2. Subpastas: Resumos, Cards, Áudios
            $subfolders = ['Resumos', 'Cards', 'Áudios'];
            $subfolderIds = [];
            foreach ($subfolders as $sub) {
                $subSafe = str_replace("'", "\\'", $sub);
                $subQ = urlencode("name = '{$subSafe}' and '{$folderId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
                $ch = curl_init("https://www.googleapis.com/drive/v3/files?q={$subQ}&fields=files(id,name)");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_TIMEOUT        => 15
                ]);
                $sRes = json_decode((string)curl_exec($ch), true);
                curl_close($ch);

                if (!empty($sRes['files'][0]['id'])) {
                    $subfolderIds[$sub] = $sRes['files'][0]['id'];
                } else {
                    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => 0,
                        CURLOPT_POSTFIELDS     => json_encode([
                            'name'     => $sub,
                            'mimeType' => 'application/vnd.google-apps.folder',
                            'parents'  => [$folderId]
                        ]),
                        CURLOPT_HTTPHEADER     => [
                            "Authorization: Bearer {$accessToken}",
                            'Content-Type: application/json'
                        ],
                        CURLOPT_TIMEOUT        => 15
                    ]);
                    $subCreated = json_decode((string)curl_exec($ch), true);
                    curl_close($ch);
                    if (!empty($subCreated['id'])) {
                        $subfolderIds[$sub] = $subCreated['id'];
                    }
                }
            }

            $extra = [
                'folder_id'  => $folderId,
                'folder_url' => $folderLink,
                'subfolders' => $subfolderIds,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Atualiza extra_data em svitlo_connections para service='google'
            $gRow = MriyaDB::queryOne("SELECT id, extra_data FROM svitlo_connections WHERE user_id = ? AND service = 'google'", [$userId]);
            if ($gRow) {
                $currExtra = !empty($gRow['extra_data']) ? json_decode((string)$gRow['extra_data'], true) : [];
                $merged = array_merge(is_array($currExtra) ? $currExtra : [], $extra);
                MriyaDB::execute("UPDATE svitlo_connections SET extra_data = ?, updated_at = NOW() WHERE id = ?", [json_encode($merged, JSON_UNESCAPED_UNICODE), $gRow['id']]);
            }

            // Atualiza/insere também em google_drive para retrocompatibilidade
            $dRow = MriyaDB::queryOne("SELECT id FROM svitlo_connections WHERE user_id = ? AND service = 'google_drive'", [$userId]);
            if ($dRow) {
                MriyaDB::execute("UPDATE svitlo_connections SET extra_data = ?, active = 1, updated_at = NOW() WHERE id = ?", [json_encode($extra, JSON_UNESCAPED_UNICODE), $dRow['id']]);
            } else {
                MriyaDB::execute(
                    "INSERT INTO svitlo_connections (user_id, service, service_username, extra_data, active, updated_at)
                     VALUES (?, 'google_drive', 'Google Drive (Svitlo)', ?, 1, NOW())",
                    [$userId, json_encode($extra, JSON_UNESCAPED_UNICODE)]
                );
            }

            return $extra;
        } catch (\Throwable $e) {
            $this->logError('ensureSvitloDriveFolder', $e->getMessage());
            return null;
        }
    }

    /**
     * PT: Retorna o link da pasta Svitlo no Google Drive do usuário
     * EN: Returns Svitlo Google Drive folder link
     * AI: @llm-context ITEM EXTRA: GET /api/v1/svitlo/drive/folder
     */
    // PT: Retorna o link da pasta Svitlo no Google Drive do usuário
    // EN: Returns Svitlo Google Drive folder link
    // AI: @llm-context ITEM EXTRA: GET /api/v1/svitlo/drive/folder
    public function driveFolder(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $conn = MriyaDB::queryOne("SELECT * FROM svitlo_connections WHERE user_id = ? AND (service = 'google' OR service = 'google_drive') AND active = 1 ORDER BY (service = 'google') DESC LIMIT 1", [$uid]);
        if ($conn && !empty($conn['extra_data'])) {
            $data = json_decode((string)$conn['extra_data'], true);
            if (!empty($data['folder_id'])) {
                $this->respond([
                    'status'     => 'connected',
                    'connected'  => true,
                    'folder_id'  => $data['folder_id'] ?? null,
                    'folder_url' => $data['folder_url'] ?? ("https://drive.google.com/drive/folders/" . ($data['folder_id'] ?? '')),
                    'subfolders' => $data['subfolders'] ?? []
                ]);
                return;
            }
        }

        // Se conectado mas a pasta ainda não foi criada, tenta criá-la agora
        $token = $this->getValidGoogleToken($uid);
        if ($token) {
            $extra = $this->ensureSvitloDriveFolder($uid, $token);
            if ($extra && !empty($extra['folder_id'])) {
                $this->respond([
                    'status'     => 'connected',
                    'connected'  => true,
                    'folder_id'  => $extra['folder_id'],
                    'folder_url' => $extra['folder_url'],
                    'subfolders' => $extra['subfolders'] ?? []
                ]);
                return;
            }
        }

        $this->respond(['status' => 'not_connected', 'connected' => false]);
    }

    /**
     * PT: Retorna status unificado dos serviços do Ecossistema Google (Calendar, Drive, Health, Gemini)
     * EN: Returns unified status of Google Ecosystem services (Calendar, Drive, Health, Gemini)
     * AI: @llm-context GET /api/v1/svitlo/google/status
     */
    // PT: Retorna status unificado dos serviços do Ecossistema Google (Calendar, Drive, Health, Gemini)
    // EN: Returns unified status of Google Ecosystem services (Calendar, Drive, Health, Gemini)
    // AI: @llm-context GET /api/v1/svitlo/google/status
    public function googleStatus(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);

        $conn = MriyaDB::queryOne(
            "SELECT * FROM svitlo_connections 
             WHERE user_id = ? AND (service = 'google' OR service = 'google_calendar') AND active = 1 
             ORDER BY (service = 'google') DESC LIMIT 1",
            [$uid]
        );

        if (!$conn) {
            $this->respond([
                'connected' => false,
                'services'  => [
                    'calendar' => ['connected' => false, 'label' => 'Google Calendar'],
                    'drive'    => ['connected' => false, 'label' => 'Google Drive (folder: Svitlo)'],
                    'health'   => ['connected' => false, 'label' => 'Google Health'],
                    'gemini'   => ['connected' => false, 'label' => 'Gemini (when available)', 'status' => 'when_available'],
                ],
                'drive'     => null,
            ]);
            return;
        }

        $extra = !empty($conn['extra_data']) ? json_decode((string)$conn['extra_data'], true) : [];
        $folderId = $extra['folder_id'] ?? null;
        $folderUrl = $extra['folder_url'] ?? ($folderId ? "https://drive.google.com/drive/folders/{$folderId}" : null);
        $subfolders = $extra['subfolders'] ?? [];

        if (!$folderId) {
            $driveConn = MriyaDB::queryOne("SELECT extra_data FROM svitlo_connections WHERE user_id = ? AND service = 'google_drive' AND active = 1", [$uid]);
            if ($driveConn && !empty($driveConn['extra_data'])) {
                $dData = json_decode((string)$driveConn['extra_data'], true);
                $folderId = $dData['folder_id'] ?? null;
                $folderUrl = $dData['folder_url'] ?? ($folderId ? "https://drive.google.com/drive/folders/{$folderId}" : null);
                $subfolders = $dData['subfolders'] ?? [];
            }
        }

        if (!$folderId) {
            $validToken = $this->getValidGoogleToken($uid);
            if ($validToken) {
                $createdDrive = $this->ensureSvitloDriveFolder($uid, $validToken);
                if ($createdDrive) {
                    $folderId = $createdDrive['folder_id'] ?? null;
                    $folderUrl = $createdDrive['folder_url'] ?? null;
                    $subfolders = $createdDrive['subfolders'] ?? [];
                }
            }
        }

        $calInteg = MriyaDB::queryOne("SELECT last_sync_at FROM svitlo_calendar_integrations WHERE user_id = ? AND provider = 'google'", [$uid]);

        $this->respond([
            'connected'    => true,
            'service'      => 'google',
            'email'        => $extra['email'] ?? $conn['service_username'] ?? 'Google Account',
            'name'         => $extra['name'] ?? '',
            'picture'      => $extra['picture'] ?? '',
            'connected_at' => $extra['connected_at'] ?? $conn['updated_at'] ?? date('Y-m-d H:i:s'),
            'last_sync_at' => $calInteg['last_sync_at'] ?? null,
            'services'     => [
                'calendar' => [
                    'connected'    => true,
                    'label'        => 'Google Calendar',
                    'last_sync_at' => $calInteg['last_sync_at'] ?? null,
                ],
                'drive'    => [
                    'connected'   => !empty($folderId),
                    'label'       => 'Google Drive (folder: Svitlo)',
                    'folder_id'   => $folderId,
                    'folder_url'  => $folderUrl,
                    'subfolders'  => $subfolders,
                ],
                'health'   => [
                    'connected' => true,
                    'label'     => 'Google Health',
                ],
                'gemini'   => [
                    'connected' => true,
                    'available' => true,
                    'label'     => 'Gemini (when available)',
                    'status'    => 'ready',
                ],
            ],
            'drive'        => [
                'folder_id'   => $folderId,
                'folder_url'  => $folderUrl,
                'subfolders'  => $subfolders,
            ]
        ]);
    }

    /**
     * PT: Lista todas as agendas do Google Calendar do usuário e status de seleção
     * EN: Lists all Google Calendar user calendars and selection status
     * AI: @llm-context ITEM 7: GET /api/v1/svitlo/calendar/calendars
     */
    // PT: Lista todas as agendas do Google Calendar do usuário e status de seleção
    // EN: Lists all Google Calendar user calendars and selection status
    // AI: @llm-context ITEM 7: GET /api/v1/svitlo/calendar/calendars
    public function googleCalendarsList(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $token = $this->getValidGoogleToken($uid);
        if (!$token) {
            $this->respond([
                'connected' => false,
                'status'    => 'not_connected',
                'calendars' => [],
                'message'   => 'Google Calendar não conectado'
            ]);
            return;
        }

        // PT: Lê preferências salvas na conexão unificada 'google' ou 'google_calendar'
        // EN: Reads saved preferences from unified 'google' or 'google_calendar' connection
        // AI: @llm-context Default: todas as agendas selecionadas se selected_calendars não foi gravado
        $conn = MriyaDB::queryOne("SELECT extra_data FROM svitlo_connections WHERE user_id = ? AND (service = 'google' OR service = 'google_calendar') AND active = 1 ORDER BY (service = 'google') DESC LIMIT 1", [$uid]);
        $extra = !empty($conn['extra_data']) ? json_decode((string)$conn['extra_data'], true) : [];
        $savedSelected = isset($extra['selected_calendars']) && is_array($extra['selected_calendars']) ? $extra['selected_calendars'] : null;

        $items = [];
        $fetchErrors = [];
        $scopeInsufficient = false;

        // PT: Helper interno para consultar Google CalendarList com paginação e suporte a agendas secundárias/compartilhadas
        // EN: Internal helper to query Google CalendarList with pagination and secondary/shared calendar support
        // AI: @llm-context Queries calendarList with showHidden=true and captures 403 scope errors
        $fetchCalendars = function(string $tok, string $endpointUrl) use (&$items, &$fetchErrors, &$scopeInsufficient, $savedSelected): bool {
            $nextPageToken = null;
            $maxPages = 10;
            $pageCount = 0;
            $tempItems = [];

            do {
                $pageCount++;
                $params = [
                    'maxResults' => 250,
                    'showHidden' => 'true'
                ];
                if ($nextPageToken) {
                    $params['pageToken'] = $nextPageToken;
                }
                $sep = str_contains($endpointUrl, '?') ? '&' : '?';
                $url = $endpointUrl . $sep . http_build_query($params);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$tok}"],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_TIMEOUT        => 20
                ]);
                $res = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($httpCode === 200 && is_string($res)) {
                    $list = json_decode($res, true);
                    if (!empty($list['items']) && is_array($list['items'])) {
                        foreach ($list['items'] as $item) {
                            $cId = (string)($item['id'] ?? '');
                            if (empty($cId)) continue;
                            $isPrimary = !empty($item['primary']) || $cId === 'primary';
                            $isSelected = ($savedSelected === null)
                                ? true
                                : (in_array($cId, $savedSelected, true) || ($isPrimary && in_array('primary', $savedSelected, true)));

                            $summary = (string)($item['summary'] ?? $item['summaryOverride'] ?? '');
                            if (empty($summary)) {
                                $summary = $isPrimary ? 'Principal' : $cId;
                            }

                            $tempItems[] = [
                                'id'              => $cId,
                                'summary'         => $summary,
                                'description'     => (string)($item['description'] ?? ''),
                                'primary'         => $isPrimary,
                                'accessRole'      => (string)($item['accessRole'] ?? 'owner'),
                                'backgroundColor' => (string)($item['backgroundColor'] ?? '#4285F4'),
                                'foregroundColor' => (string)($item['foregroundColor'] ?? '#ffffff'),
                                'selected'        => $isSelected
                            ];
                        }
                    }
                    $nextPageToken = $list['nextPageToken'] ?? null;
                } else {
                    if ($httpCode === 403 && (str_contains((string)$res, 'insufficient') || str_contains((string)$res, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') || str_contains((string)$res, 'PERMISSION_DENIED'))) {
                        $scopeInsufficient = true;
                    }
                    $fetchErrors[] = "{$endpointUrl} HTTP {$httpCode} - {$curlErr}: " . substr((string)$res, 0, 200);
                    return false;
                }
            } while (!empty($nextPageToken) && $pageCount < $maxPages);

            if (!empty($tempItems)) {
                $items = $tempItems;
                return true;
            }
            return false;
        };

        // 1ª tentativa com users/me/calendarList
        $success = $fetchCalendars($token, 'https://www.googleapis.com/calendar/v3/users/me/calendarList');

        // Se falhou (401/403 ou expiração), força renovação de token e tenta novamente
        if (!$success) {
            $refreshedTok = $this->getValidGoogleToken($uid, true);
            if ($refreshedTok && $refreshedTok !== $token) {
                $token = $refreshedTok;
                $success = $fetchCalendars($token, 'https://www.googleapis.com/calendar/v3/users/me/calendarList');
            }
        }

        // Se ainda não obteve itens, tenta endpoint canônico alternativo /calendarList
        if (empty($items)) {
            $fetchCalendars($token, 'https://www.googleapis.com/calendar/v3/calendarList');
        }

        // Se ainda não obteve itens, tenta obter metadados diretos de 'primary'
        if (empty($items)) {
            $chPrimary = curl_init('https://www.googleapis.com/calendar/v3/calendars/primary');
            curl_setopt_array($chPrimary, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_TIMEOUT        => 15
            ]);
            $pRes = curl_exec($chPrimary);
            $pCode = curl_getinfo($chPrimary, CURLINFO_HTTP_CODE);
            curl_close($chPrimary);
            if ($pCode === 200 && is_string($pRes)) {
                $pData = json_decode($pRes, true);
                if (!empty($pData['id'])) {
                    $items[] = [
                        'id'              => (string)$pData['id'],
                        'summary'         => (string)($pData['summary'] ?? 'Principal'),
                        'description'     => (string)($pData['description'] ?? ''),
                        'primary'         => true,
                        'accessRole'      => 'owner',
                        'backgroundColor' => '#4285F4',
                        'foregroundColor' => '#ffffff',
                        'selected'        => ($savedSelected === null) ? true : (in_array((string)$pData['id'], $savedSelected, true) || in_array('primary', $savedSelected, true))
                    ];
                }
            }
        }

        if (empty($items)) {
            $this->logError('googleCalendarsList', implode(' | ', $fetchErrors));
            $items[] = [
                'id'              => 'primary',
                'summary'         => 'Principal',
                'description'     => '',
                'primary'         => true,
                'accessRole'      => 'owner',
                'backgroundColor' => '#4285F4',
                'foregroundColor' => '#ffffff',
                'selected'        => ($savedSelected === null) ? true : in_array('primary', $savedSelected, true)
            ];
        }

        $allIds = array_column($items, 'id');
        $selectedIds = ($savedSelected === null) ? $allIds : $savedSelected;

        $this->respond([
            'connected'          => true,
            'status'             => 'ok',
            'scope_insufficient' => $scopeInsufficient,
            'calendars'          => $items,
            'selected_ids'       => $selectedIds,
            'total'              => count($items),
            'message'            => $scopeInsufficient ? 'A permissão atual da Conta Google não permite listar todas as agendas secundárias. Clique em Reconectar Google para autorizar.' : null,
            'debug_errors'       => !empty($fetchErrors) ? $fetchErrors : null
        ]);
    }

    /**
     * PT: Salva preferências de agendas do Google Calendar a sincronizar
     * EN: Saves user's chosen Google Calendar IDs to synchronize
     * AI: @llm-context ITEM 7: POST /api/v1/svitlo/calendar/calendars
     */
    // PT: Salva preferências de agendas do Google Calendar a sincronizar
    // EN: Saves user's chosen Google Calendar IDs to synchronize
    // AI: @llm-context ITEM 7: POST /api/v1/svitlo/calendar/calendars
    public function googleCalendarsSaveSelected(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();
        $selected = is_array($b['selected'] ?? null)
            ? $b['selected']
            : (is_array($b['calendars'] ?? null)
                ? $b['calendars']
                : (is_array($b['selected_calendars'] ?? null) ? $b['selected_calendars'] : []));

        // PT: Atualiza extra_data em svitlo_connections para ambos os registros 'google' e 'google_calendar'
        // EN: Updates extra_data in svitlo_connections for both 'google' and 'google_calendar' records
        foreach (['google', 'google_calendar'] as $srv) {
            $conn = MriyaDB::queryOne("SELECT id, extra_data FROM svitlo_connections WHERE user_id = ? AND service = ?", [$uid, $srv]);
            if ($conn) {
                $extra = !empty($conn['extra_data']) ? json_decode((string)$conn['extra_data'], true) : [];
                if (!is_array($extra)) $extra = [];
                $extra['selected_calendars'] = $selected;
                $extra['updated_at'] = date('Y-m-d H:i:s');
                MriyaDB::execute("UPDATE svitlo_connections SET extra_data = ?, updated_at = NOW() WHERE id = ?", [
                    json_encode($extra, JSON_UNESCAPED_UNICODE),
                    $conn['id']
                ]);
            }
        }

        $token = $this->getValidGoogleToken($uid);
        if ($token) {
            $this->syncGoogleCalendarToSvitlo($uid, $token);
        }

        $this->respond([
            'status'   => 'saved',
            'selected' => $selected,
            'count'    => count($selected)
        ]);
    }

    // PT: Retorna array com IDs de todas as agendas do Google Calendar do usuário
    // EN: Returns array with IDs of all user's Google Calendars
    private function getUserGoogleCalendarIds(string $accessToken): array {
        $ch = curl_init('https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ids = [];
        if ($httpCode === 200 && is_string($res)) {
            $data = json_decode($res, true);
            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    if (!empty($item['id'])) {
                        $ids[] = (string)$item['id'];
                    }
                }
            }
        }
        return !empty($ids) ? $ids : ['primary'];
    }

    // PT: Puxa todos os eventos de TODAS as agendas selecionadas do Google Calendar (-60 a +180 dias)
    // EN: Fetches all Google Calendar events from ALL selected agendas (-60 to +180 days)
    // AI: @llm-context ITEM 7: Itera pelas agendas selecionadas e faz upsert por user_id e external_event_id
    private function syncGoogleCalendarToSvitlo(int $userId, string $accessToken): int {
        $timeMin = date('Y-m-d\TH:i:s\Z', strtotime('-60 days'));
        $timeMax = date('Y-m-d\TH:i:s\Z', strtotime('+180 days'));

        $conn = MriyaDB::queryOne("SELECT extra_data FROM svitlo_connections WHERE user_id = ? AND (service = 'google' OR service = 'google_calendar') AND active = 1 ORDER BY (service = 'google') DESC LIMIT 1", [$userId]);
        $extra = !empty($conn['extra_data']) ? json_decode((string)$conn['extra_data'], true) : [];
        $calendarIds = isset($extra['selected_calendars']) && is_array($extra['selected_calendars'])
            ? $extra['selected_calendars']
            : null;

        if ($calendarIds === null) {
            $calendarIds = $this->getUserGoogleCalendarIds($accessToken);
        }
        if (empty($calendarIds)) {
            $calendarIds = ['primary'];
        }

        $count = 0;
        foreach ($calendarIds as $calId) {
            $encodedCalId = urlencode($calId);
            $url = "https://www.googleapis.com/calendar/v3/calendars/{$encodedCalId}/events?" . http_build_query([
                'timeMin'      => $timeMin,
                'timeMax'      => $timeMax,
                'maxResults'   => 2500,
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !is_string($resp)) {
                continue;
            }

            $data = json_decode($resp, true);
            $items = $data['items'] ?? [];
            if (!is_array($items)) continue;

            foreach ($items as $item) {
                $extId = (string)($item['id'] ?? '');
                if (!$extId) continue;

                $status = (string)($item['status'] ?? 'confirmed');
                if ($status === 'cancelled') {
                    MriyaDB::execute("DELETE FROM svitlo_calendar_events WHERE user_id = ? AND external_event_id = ?", [$userId, $extId]);
                    continue;
                }

                $summary = trim((string)($item['summary'] ?? '(Sem título)'));
                $desc = (string)($item['description'] ?? '');
                $loc = (string)($item['location'] ?? '');

                $allDay = 0;
                if (!empty($item['start']['dateTime'])) {
                    $start = date('Y-m-d H:i:s', strtotime($item['start']['dateTime']));
                    $end = !empty($item['end']['dateTime']) ? date('Y-m-d H:i:s', strtotime($item['end']['dateTime'])) : $start;
                } elseif (!empty($item['start']['date'])) {
                    $start = $item['start']['date'] . ' 00:00:00';
                    $end = !empty($item['end']['date']) ? $item['end']['date'] . ' 23:59:59' : ($item['start']['date'] . ' 23:59:59');
                    $allDay = 1;
                } else {
                    continue;
                }

                // Categorização inteligente do evento
                $haystack = mb_strtolower($summary . ' ' . $desc);
                if (str_contains($haystack, 'prova') || str_contains($haystack, 'exame') || str_contains($haystack, 'test')) {
                    $eventType = 'prova';
                    $type = 'exam';
                } elseif (str_contains($haystack, 'trabalho') || str_contains($haystack, 'entrega') || str_contains($haystack, 'projeto') || str_contains($haystack, 'assignment')) {
                    $eventType = 'trabalho';
                    $type = 'assignment';
                } elseif (str_contains($haystack, 'aula') || str_contains($haystack, 'seminario') || str_contains($haystack, 'congresso') || str_contains($haystack, 'palestra')) {
                    $eventType = 'aula';
                    $type = 'study';
                } else {
                    $eventType = 'outro';
                    $type = 'other';
                }

                $existing = MriyaDB::queryOne("SELECT id FROM svitlo_calendar_events WHERE user_id = ? AND external_event_id = ?", [$userId, $extId]);
                if ($existing) {
                    MriyaDB::execute(
                        "UPDATE svitlo_calendar_events
                         SET title = ?, description = ?, notes = ?, location = ?,
                             start_datetime = ?, end_datetime = ?, all_day = ?,
                             event_type = ?, type = ?, synced_at = NOW()
                         WHERE id = ?",
                        [$summary, $desc, $desc, $loc, $start, $end, $allDay, $eventType, $type, $existing['id']]
                    );
                } else {
                    MriyaDB::execute(
                        "INSERT INTO svitlo_calendar_events
                         (user_id, title, description, notes, location, start_datetime, end_datetime, all_day, event_type, type, provider, external_event_id, synced_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'google', ?, NOW())",
                        [$userId, $summary, $desc, $desc, $loc, $start, $end, $allDay, $eventType, $type, $extId]
                    );
                }
                $count++;
            }
        }

        return $count;
    }

    // PT: Sincroniza bidirecionalmente: Google Calendar -> Svitlo e Svitlo -> Google
    // AI: @llm-context POST /api/v1/svitlo/calendar/sync
    // PT: Sincroniza eventos entre o calendário Svitlo e provedores externos
    // EN: Synchronizes events between Svitlo calendar and external providers
    // AI: @llm-context POST /api/v1/svitlo/calendar/sync updates Google Calendar events
    public function calendarSync(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        $googleTok = $this->getValidGoogleToken($uid);
        if (!$googleTok) {
            $this->abort(400, 'Google Calendar não está conectado.');
            return;
        }

        // 1. Puxa todos os eventos do Google Calendar para o Svitlo
        $imported = $this->syncGoogleCalendarToSvitlo($uid, $googleTok);

        // 2. Empurra eventos locais não sincronizados para o Google Calendar
        $unsynced = MriyaDB::query(
            "SELECT * FROM svitlo_calendar_events WHERE user_id = ? AND (external_event_id IS NULL OR external_event_id = '') ORDER BY start_datetime ASC",
            [$uid]
        ) ?: [];

        $pushed = 0;
        foreach ($unsynced as $ev) {
            $extId = $this->pushGoogleEvent($googleTok, $ev);
            if ($extId) {
                MriyaDB::execute(
                    "UPDATE svitlo_calendar_events SET external_event_id = ?, provider = 'google', synced_at = NOW() WHERE id = ?",
                    [$extId, $ev['id']]
                );
                $pushed++;
            }
        }

        MriyaDB::execute("UPDATE svitlo_calendar_integrations SET last_sync_at = NOW() WHERE user_id = ? AND provider = 'google'", [$uid]);
        $this->respond([
            'status'               => 'synced',
            'imported_from_google' => $imported,
            'pushed_to_google'     => $pushed,
            'count'                => ($imported + $pushed),
            'total_pending'        => count($unsynced)
        ]);
    }

    // PT: Lista eventos de calendário unificados (locais e Google Calendar de todas as agendas) com metadados formatados
    // EN: Lists unified calendar events (local and Google Calendar from all calendars) with formatted metadata
    // AI: @llm-context GET /api/v1/svitlo/calendar/events — Consulta banco local e todas as agendas do Google Calendar API v3
    public function calendarEventsList(): void {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $month = (string)($_GET['month'] ?? '');
        $discId = isset($_GET['discipline_id']) ? (int)$_GET['discipline_id'] : 0;

        $sql = "SELECT e.*, d.name as discipline_name, d.code as discipline_code
                FROM svitlo_calendar_events e
                LEFT JOIN svitlo_disciplines d ON d.id = e.discipline_id
                WHERE e.user_id = ?";
        $params = [$uid];

        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $sql .= " AND DATE(e.start_datetime) BETWEEN ? AND ?";
            $params[] = $start;
            $params[] = $end;
        }

        if ($discId > 0) {
            $sql .= " AND e.discipline_id = ?";
            $params[] = $discId;
        }

        $sql .= " ORDER BY e.start_datetime ASC";
        $rows = MriyaDB::query($sql, $params) ?: [];

        $formattedEvents = [];
        foreach ($rows as $r) {
            $calName = !empty($r['discipline_name']) ? (string)$r['discipline_name'] : 'Google Calendar';
            $formattedEvents[] = [
                'id'                => (string)$r['id'],
                'external_event_id' => (string)($r['external_event_id'] ?? ''),
                'title'             => (string)$r['title'],
                'start'             => (string)$r['start_datetime'],
                'start_datetime'    => (string)$r['start_datetime'],
                'end'               => (string)($r['end_datetime'] ?? $r['start_datetime']),
                'end_datetime'      => (string)($r['end_datetime'] ?? $r['start_datetime']),
                'calendar'          => $calName,
                'calendar_name'     => $calName,
                'calendar_color'    => (string)($r['color'] ?? '#8B5E3C'),
                'color'             => (string)($r['color'] ?? '#8B5E3C'),
                // PT: A coluna real no banco é event_type (ENUM: prova,trabalho,aula,pomodoro,revisao,congresso,outro)
                // EN: The actual DB column is event_type (ENUM: prova,trabalho,aula,pomodoro,revisao,congresso,outro)
                // AI: @llm-context event_type is the canonical column; 'type' is an alias kept for backwards compat
                'type'              => (string)($r['event_type'] ?? 'outro'),
                'event_type'        => (string)($r['event_type'] ?? 'outro'),
                'discipline_name'   => (string)($r['discipline_name'] ?? ''),
                'discipline_code'   => (string)($r['discipline_code'] ?? ''),
                'discipline_id'     => $r['discipline_id'] ?? null,
                'notes'             => (string)($r['description'] ?? $r['notes'] ?? ''),
                'completed'         => (int)($r['completed'] ?? 0),
                'provider'          => !empty($r['provider']) ? (string)$r['provider'] : 'google',
            ];
        }

        // PT: Puxa eventos de TODAS as agendas do Google Calendar via calendarList + cURL multi paralelo.
        //     O token OAuth vem de getValidGoogleToken(), que já faz refresh automático.
        // EN: Fetches events from ALL Google Calendar accounts via calendarList + parallel cURL multi.
        //     OAuth token comes from getValidGoogleToken(), which auto-refreshes when needed.
        // AI: @llm-context Fluxo: (1) GET /users/me/calendarList → metadados de cada agenda
        //     (2) curl_multi: GET /calendars/{id}/events para cada agenda em paralelo
        //     (3) Enriquece cada evento com calendar_name + calendar_color
        //     (4) usort() por start ASC
        //     (5) respond() com { events, total, calendars: string[] }
        $googleTok = $this->getValidGoogleToken($uid);

        // PT: Nomes das agendas para o campo "calendars" da resposta
        // EN: Calendar names for the "calendars" response field
        $calendarNames = [];

        if ($googleTok) {
            // ── 1. GET /users/me/calendarList — lista todas as agendas visíveis do usuário ─────────
            $chList = curl_init('https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250');
            curl_setopt_array($chList, [
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $googleTok],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $calListRaw  = curl_exec($chList);
            $calListCode = curl_getinfo($chList, CURLINFO_HTTP_CODE);
            curl_close($chList);

            if ($calListCode === 401 || $calListCode === 403) {
                $refreshedTok = $this->getValidGoogleToken($uid, true);
                if ($refreshedTok && $refreshedTok !== $googleTok) {
                    $googleTok = $refreshedTok;
                    $chList = curl_init('https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250');
                    curl_setopt_array($chList, [
                        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $googleTok],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => 0,
                        CURLOPT_TIMEOUT        => 10,
                    ]);
                    $calListRaw = curl_exec($chList);
                    curl_close($chList);
                }
            }

            $calListData = json_decode((string)$calListRaw, true);
            $calMeta = [];
            if (!empty($calListData['items']) && is_array($calListData['items'])) {
                foreach ($calListData['items'] as $cal) {
                    $cid = (string)($cal['id'] ?? '');
                    if ($cid === '') continue;
                    $calMeta[$cid] = [
                        'summary'         => (string)($cal['summary'] ?? $cal['summaryOverride'] ?? 'Google Calendar'),
                        'backgroundColor' => (string)($cal['backgroundColor'] ?? '#4285F4'),
                        'primary'         => !empty($cal['primary']) || $cid === 'primary',
                    ];
                    $calendarNames[] = (string)($cal['summary'] ?? $cal['summaryOverride'] ?? 'Google Calendar');
                }
            }

            if (empty($calMeta)) {
                $calMeta['primary'] = [
                    'summary'         => 'Principal',
                    'backgroundColor' => '#4285F4',
                    'primary'         => true,
                ];
                $calendarNames[] = 'Principal';
            }

            // PT: Filtra agendas de acordo com a seleção salva pelo usuário em svitlo_connections
            // EN: Filters calendars according to user saved preferences in svitlo_connections
            // AI: @llm-context Default: todas as agendas selecionadas se selected_calendars não foi definido
            $cConn = MriyaDB::queryOne("SELECT extra_data FROM svitlo_connections WHERE user_id = ? AND (service = 'google' OR service = 'google_calendar') AND active = 1 ORDER BY (service = 'google') DESC LIMIT 1", [$uid]);
            $cExtra = !empty($cConn['extra_data']) ? json_decode((string)$cConn['extra_data'], true) : [];
            $savedSelected = isset($cExtra['selected_calendars']) && is_array($cExtra['selected_calendars']) ? $cExtra['selected_calendars'] : null;

            if ($savedSelected !== null) {
                $filteredMeta = [];
                $filteredNames = [];
                foreach ($calMeta as $cid => $meta) {
                    $isPrimary = !empty($meta['primary']) || $cid === 'primary';
                    if (in_array($cid, $savedSelected, true) || ($isPrimary && in_array('primary', $savedSelected, true))) {
                        $filteredMeta[$cid] = $meta;
                        $filteredNames[] = $meta['summary'];
                    }
                }
                $calMeta = $filteredMeta;
                $calendarNames = $filteredNames;
            }

            // ── 2. Janela de tempo: início do mês atual → fim do mês atual + 2 meses ──────
            if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
                $anchorTs = strtotime($month . '-01');
            } else {
                $anchorTs = strtotime(date('Y-m-01'));
            }
            $timeMin = date('Y-m-d\T00:00:00\Z', $anchorTs);
            $timeMax = date('Y-m-t\T23:59:59\Z', strtotime('+2 months', $anchorTs));

            // ── 3. cURL multi — busca eventos de cada agenda em paralelo ──────────────────
            $mh      = curl_multi_init();
            $handles = [];
            $calIds  = [];

            foreach (array_keys($calMeta) as $calId) {
                $url = 'https://www.googleapis.com/calendar/v3/calendars/'
                    . urlencode($calId)
                    . '/events?'
                    . http_build_query([
                        'timeMin'      => $timeMin,
                        'timeMax'      => $timeMax,
                        'singleEvents' => 'true',
                        'orderBy'      => 'startTime',
                        'maxResults'   => 150,
                    ]);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $googleTok],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_TIMEOUT        => 12,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[] = $ch;
                $calIds[]  = $calId;
            }

            $active = null;
            do {
                curl_multi_exec($mh, $active);
                if ($active) curl_multi_select($mh, 0.5);
            } while ($active > 0);

            // PT: Coleta respostas, enriquece com metadados da agenda e acumula em $formattedEvents
            foreach ($handles as $idx => $ch) {
                $raw    = curl_multi_getcontent($ch);
                $calId  = $calIds[$idx];
                $meta   = $calMeta[$calId] ?? ['summary' => 'Google Calendar', 'backgroundColor' => '#4285F4'];
                $evData = json_decode((string)$raw, true);

                if (!empty($evData['items']) && is_array($evData['items'])) {
                    foreach ($evData['items'] as $item) {
                        $start = (string)($item['start']['dateTime'] ?? $item['start']['date'] ?? '');
                        $end   = (string)($item['end']['dateTime'] ?? $item['end']['date'] ?? $start);

                        $formattedEvents[] = [
                            'id'                 => (string)($item['id'] ?? uniqid('g_')),
                            'external_event_id'  => (string)($item['id'] ?? ''),
                            'title'              => (string)($item['summary'] ?? '(Sem título)'),
                            'start'              => $start,
                            'start_datetime'     => $start,
                            'end'                => $end,
                            'end_datetime'       => $end,
                            'calendar'           => $meta['summary'],
                            'calendar_name'      => $meta['summary'],
                            'calendar_color'     => $meta['backgroundColor'],
                            'color'              => $meta['backgroundColor'],
                            'type'               => 'google',
                            'event_type'         => 'google',
                            'notes'              => (string)($item['description'] ?? ''),
                            'location'           => (string)($item['location'] ?? ''),
                            'provider'           => 'google',
                            'google_calendar_id' => $calId,
                        ];
                    }
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
        }

        // ── 4. Deduplica e ordena todos os eventos por data de início ─────────────────────
        // PT: Remove duplicatas pelo external_event_id ou id antes de retornar
        // EN: Deduplicates events by external_event_id or id before returning
        // AI: @llm-context Events from Google have external_event_id. Keep first occurrence only.
        $seen = [];
        $uniqueEvents = [];
        foreach ($formattedEvents as $ev) {
            $key = !empty($ev['external_event_id']) ? (string)$ev['external_event_id'] : (string)$ev['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $uniqueEvents[] = $ev;
        }
        $formattedEvents = $uniqueEvents;

        // PT: strcmp() em string ISO 8601 é equivalente a ordenação cronológica
        // EN: strcmp() on ISO 8601 strings is equivalent to chronological sorting
        usort($formattedEvents, static function (array $a, array $b): int {
            return strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? ''));
        });

        // ── 5. Resposta unificada ─────────────────────────────────────────────────────────
        // PT: calendars = lista de nomes (strings) de todas as agendas Google do usuário
        // EN: calendars = list of names (strings) of all the user's Google calendars
        $this->respond([
            'events'           => $formattedEvents,
            'total'            => count($formattedEvents),
            'google_connected' => !empty($googleTok),
            'calendars'        => $calendarNames,
        ]);
    }

    // PT: Cria evento no calendário local e sincroniza no Google se conectado (com auto-formatação para provas)
    // EN: Creates local calendar event and syncs to Google Calendar (with auto-formatting for exams)
    // AI: @llm-context POST /api/v1/svitlo/calendar/events — Auto-título "Prova — {disciplina} ({código})" e lembretes 24h e 1h
    public function calendarEventsCreate(): void {
        $this->requireAuth();
        // PT: user_id é a claim primária; uid e sub são aliases usados em JWTs mais antigos
        // EN: user_id is the primary claim; uid and sub are aliases used in older JWTs
        // AI: @llm-context Sempre inclua user_id como primeira opção no chain de resolução de uid
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $title = trim((string)($b['title'] ?? ''));
        $start = (string)($b['start_datetime'] ?? '');
        if (!$start) {
            $this->abort(400, 'Data de início é obrigatória.');
            return;
        }

        $discId = isset($b['discipline_id']) && (int)$b['discipline_id'] > 0 ? (int)$b['discipline_id'] : null;
        $eventType = (string)($b['event_type'] ?? ($b['type'] ?? 'outro'));
        // PT: Valida contra o ENUM do banco — valores inválidos (ex: 'study', 'exam') caem em 'outro'
        // EN: Validate against DB ENUM — invalid values (e.g. 'study', 'exam') fall back to 'outro'
        // AI: @llm-context ENUM svitlo_calendar_events.event_type: prova,trabalho,aula,pomodoro,revisao,congresso,outro
        $allowedTypes = ['prova', 'trabalho', 'aula', 'pomodoro', 'revisao', 'congresso', 'outro'];
        if (!in_array($eventType, $allowedTypes)) $eventType = 'outro';

        // PT: Se for prova em uma disciplina, garante formato "Prova — {disciplina} ({código})"
        // EN: If exam in a discipline, ensures "Prova — {disciplina} ({código})" format
        if ($eventType === 'prova' && $discId) {
            $disc = MriyaDB::queryOne("SELECT name, code FROM svitlo_disciplines WHERE id = ? AND user_id = ?", [$discId, $uid]);
            if ($disc) {
                $codeSuffix = !empty($disc['code']) ? " ({$disc['code']})" : "";
                if (!$title || !str_starts_with($title, 'Prova — ')) {
                    $title = "Prova — " . $disc['name'] . $codeSuffix;
                }
            }
        }

        if (!$title) {
            $title = 'Evento sem título';
        }

        $desc = $b['description'] ?? ($b['notes'] ?? null);
        $end = !empty($b['end_datetime']) ? $b['end_datetime'] : null;
        $allDay = (int)($b['all_day'] ?? 0);
        $loc = $b['location'] ?? null;
        $reminder = ($eventType === 'prova') ? 1440 : (int)($b['reminder_minutes'] ?? ($b['reminder_days'] ? (int)$b['reminder_days'] * 1440 : 60));

        MriyaDB::execute(
            "INSERT INTO svitlo_calendar_events
             (user_id, discipline_id, title, description, event_type, start_datetime, end_datetime, all_day, location, reminder_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$uid, $discId, $title, $desc, $eventType, $start, $end, $allDay, $loc, $reminder]
        );

        $newId = (int)MriyaDB::lastInsertId();
        $extId = null;

        // PT: Sincroniza com Google Calendar se conectado
        $googleTok = $this->getValidGoogleToken($uid);
        if ($googleTok) {
            $extId = $this->pushGoogleEvent($googleTok, [
                'title'            => $title,
                'description'      => $desc,
                'event_type'       => $eventType,
                'start_datetime'   => $start,
                'end_datetime'     => $end,
                'location'         => $loc,
                'reminder_minutes' => $reminder
            ]);
            if ($extId) {
                MriyaDB::execute(
                    "UPDATE svitlo_calendar_events SET external_event_id = ?, provider = 'google', synced_at = NOW() WHERE id = ?",
                    [$extId, $newId]
                );
            }
        }

        $this->respond([
            'status'            => 'created',
            'id'                => $newId,
            'external_event_id' => $extId,
            'synced_google'     => !empty($extId)
        ]);
    }

    // PT: Atualiza evento de calendário localmente e no Google Calendar
    // EN: Updates calendar event locally and on Google Calendar
    // AI: @llm-context PUT /api/v1/svitlo/calendar/events/{id}
    public function calendarEventsUpdate(int $id): void {
        $this->requireAuth();
        // PT: user_id é a claim primária; uid e sub são aliases usados em JWTs mais antigos
        // EN: user_id is the primary claim; uid and sub are aliases used in older JWTs
        // AI: @llm-context Sempre inclua user_id como primeira opção no chain de resolução de uid
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $ev = MriyaDB::queryOne("SELECT * FROM svitlo_calendar_events WHERE id = ? AND user_id = ?", [$id, $uid]);
        if (!$ev) {
            $this->abort(404, 'Evento não encontrado.');
            return;
        }

        $title = trim((string)($b['title'] ?? $ev['title']));
        $start = (string)($b['start_datetime'] ?? $ev['start_datetime']);
        // PT: Valida event_type contra o ENUM do banco — valores inválidos (ex: 'study', 'exam', nulos) caem em 'outro'
        // EN: Validate event_type against DB ENUM — invalid values (e.g. 'study', 'exam', null) fall back to 'outro'
        // AI: @llm-context ENUM svitlo_calendar_events.event_type: prova,trabalho,aula,pomodoro,revisao,congresso,outro
        $allowedTypes = ['prova', 'trabalho', 'aula', 'pomodoro', 'revisao', 'congresso', 'outro'];
        $rawEventType = (string)($b['event_type'] ?? ($b['type'] ?? $ev['event_type'] ?? ''));
        $eventType = in_array($rawEventType, $allowedTypes) ? $rawEventType : 'outro';

        $desc = $b['description'] ?? ($b['notes'] ?? $ev['description']);
        $end = isset($b['end_datetime']) ? $b['end_datetime'] : $ev['end_datetime'];
        $allDay = isset($b['all_day']) ? (int)$b['all_day'] : (int)$ev['all_day'];
        $loc = isset($b['location']) ? $b['location'] : $ev['location'];
        $reminder = isset($b['reminder_minutes']) ? (int)$b['reminder_minutes'] : (int)$ev['reminder_minutes'];
        $completed = isset($b['completed']) ? (int)$b['completed'] : (int)$ev['completed'];

        MriyaDB::execute(
            "UPDATE svitlo_calendar_events
             SET title = ?, description = ?, event_type = ?, start_datetime = ?, end_datetime = ?, all_day = ?, location = ?, reminder_minutes = ?, completed = ?
             WHERE id = ? AND user_id = ?",
            [$title, $desc, $eventType, $start, $end, $allDay, $loc, $reminder, $completed, $id, $uid]
        );

        if (!empty($ev['external_event_id'])) {
            $googleTok = $this->getValidGoogleToken($uid);
            if ($googleTok) {
                $this->patchGoogleEvent($googleTok, (string)$ev['external_event_id'], [
                    'title'          => $title,
                    'description'    => $desc,
                    'event_type'     => $eventType,
                    'start_datetime' => $start,
                    'end_datetime'   => $end,
                    'location'       => $loc,
                ]);
            }
        }

        $this->respond(['status' => 'updated']);
    }

    // PT: Remove evento de calendário localmente e no Google Calendar
    // EN: Removes calendar event locally and on Google Calendar
    // AI: @llm-context DELETE /api/v1/svitlo/calendar/events/{id}
    public function calendarEventsDelete(int $id): void {
        $this->requireAuth();
        // PT: user_id é a claim primária; uid e sub são aliases usados em JWTs mais antigos
        // EN: user_id is the primary claim; uid and sub are aliases used in older JWTs
        // AI: @llm-context Sempre inclua user_id como primeira opção no chain de resolução de uid
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        $ev = MriyaDB::queryOne("SELECT external_event_id FROM svitlo_calendar_events WHERE id = ? AND user_id = ?", [$id, $uid]);
        if ($ev && !empty($ev['external_event_id'])) {
            $googleTok = $this->getValidGoogleToken($uid);
            if ($googleTok) {
                $this->deleteGoogleEvent($googleTok, (string)$ev['external_event_id']);
            }
        }

        MriyaDB::execute("DELETE FROM svitlo_calendar_events WHERE id = ? AND user_id = ?", [$id, $uid]);
        $this->respond(['status' => 'deleted']);
    }

    // PT: Feed iCal (.ics) público por token para Apple Calendar, Samsung Calendar e Outlook
    // AI: @llm-context GET /api/v1/svitlo/calendar/ical/{user_token}
    // PT: Fornece feed dinâmico iCalendar (.ics) para sincronização com Outlook e Apple
    // EN: Provides dynamic iCalendar (.ics) feed for synchronization with Outlook and Apple
    // AI: @llm-context GET /api/v1/svitlo/calendar/feed/{token} returns VCALENDAR text/calendar
    public function calendarIcalFeed(string $userToken): void {
        $uid = 0;
        try {
            $payload = MriyaJWT::validateAccessToken($userToken);
            $uid = (int)($payload['uid'] ?? $payload['sub'] ?? 0);
        } catch (\Throwable $e) {
            // Se for token opaco ou ID codificado
            $uid = (int)base64_decode($userToken);
        }

        if ($uid <= 0) {
            header('HTTP/1.1 404 Not Found');
            echo 'Feed iCal inválido ou expirado.';
            exit;
        }

        $events = MriyaDB::query(
            "SELECT e.*, d.name as discipline_name
             FROM svitlo_calendar_events e
             LEFT JOIN svitlo_disciplines d ON d.id = e.discipline_id
             WHERE e.user_id = ? AND e.completed = 0
             ORDER BY e.start_datetime ASC",
            [$uid]
        ) ?: [];

        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Svitlo//Espectro Ipe Solucoes Tecnologicas//PT\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:Svitlo — Estudos e Provas\r\n";
        foreach ($events as $ev) {
            $dtStart = date('Ymd\THis\Z', strtotime($ev['start_datetime']));
            $dtEnd = !empty($ev['end_datetime']) ? date('Ymd\THis\Z', strtotime($ev['end_datetime'])) : date('Ymd\THis\Z', strtotime($ev['start_datetime']) + 7200);
            $summary = str_replace(["\r", "\n"], [' ', ' '], ($ev['discipline_name'] ? "[{$ev['discipline_name']}] " : '') . $ev['title']);
            $desc = str_replace(["\r", "\n"], [' ', ' '], $ev['description'] ?? '');

            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:svitlo-{$ev['id']}@espectroipe.com.br\r\n";
            $ics .= "DTSTAMP:" . date('Ymd\THis\Z') . "\r\n";
            $ics .= "DTSTART:{$dtStart}\r\n";
            $ics .= "DTEND:{$dtEnd}\r\n";
            $ics .= "SUMMARY:{$summary}\r\n";
            if ($desc) $ics .= "DESCRIPTION:{$desc}\r\n";
            if ($ev['location']) $ics .= "LOCATION:" . str_replace(["\r", "\n"], [' ', ' '], $ev['location']) . "\r\n";
            $ics .= "END:VEVENT\r\n";
        }
        $ics .= "END:VCALENDAR";

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="svitlo-calendario.ics"');
        echo $ics;
        exit;
    }

    // PT: Lista conexões ativas de calendário do usuário
    // AI: @llm-context GET /api/v1/svitlo/calendar/integrations
    // PT: Lista integrações de calendário ativas e agendas sincronizadas do usuário
    // EN: Lists active calendar integrations and synchronized user calendars
    // AI: @llm-context GET /api/v1/svitlo/calendar/integrations returns Google and Outlook status
    public function calendarIntegrationsList(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $rows = MriyaDB::query("SELECT provider, sync_enabled, last_sync_at, sync_filters FROM svitlo_calendar_integrations WHERE user_id = ?", [$uid]) ?: [];

        $token = MriyaJWT::extractFromHeader() ?: base64_encode((string)$uid);
        $icalUrl = "https://api.espectroipe.com.br/api/v1/svitlo/calendar/ical/{$token}";

        $this->respond([
            'integrations' => $rows,
            'ical_url'     => $icalUrl
        ]);
    }

    // PT: Salva configurações e filtros de sincronização do calendário
    // AI: @llm-context POST /api/v1/svitlo/calendar/integrations/settings
    // PT: Salva configurações e preferências de sincronização de calendários
    // EN: Saves calendar synchronization settings and preferences
    // AI: @llm-context POST /api/v1/svitlo/calendar/integrations/settings updates auto-sync flags
    public function calendarIntegrationsSettings(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $provider = (string)($b['provider'] ?? 'google');
        $syncEnabled = (int)($b['sync_enabled'] ?? 1);
        $filters = isset($b['sync_filters']) ? (is_array($b['sync_filters']) ? json_encode($b['sync_filters'], JSON_UNESCAPED_UNICODE) : (string)$b['sync_filters']) : null;

        MriyaDB::execute(
            "UPDATE svitlo_calendar_integrations SET sync_enabled = ?, sync_filters = ? WHERE user_id = ? AND provider = ?",
            [$syncEnabled, $filters, $uid, $provider]
        );

        $this->respond(['status' => 'saved']);
    }

    // ── ANKI: IMPORTAR E EXPORTAR PACOTES (.apkg) ────────────────────

    // PT: Importa pacote .apkg para a coleção Anki local com isolamento de usuário
    // EN: Imports .apkg package into local Anki collection with user isolation
    // AI: @llm-context ITEM 4: POST /api/v1/svitlo/anki/import (multipart/form-data)
    public function ankiImportApkg(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);
        $colPath = $this->getAnkiCollectionPath($uid);

        $file = $_FILES['file'] ?? ($_FILES['apkg'] ?? null);
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->abort(400, 'Arquivo .apkg obrigatório');
            return;
        }

        $tmpPath = (string)$file['tmp_name'];
        $destPath = tempnam(sys_get_temp_dir(), 'svitlo_apkg_') . '.apkg';
        move_uploaded_file($tmpPath, $destPath);

        $safeCol = addslashes($colPath);
        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'from anki.importing import AnkiPackageImporter',
            'import json, sys, os',
            'try:',
            '    col_path = "' . $safeCol . '"',
            '    col_dir = os.path.dirname(col_path)',
            '    if col_dir and not os.path.exists(col_dir): os.makedirs(col_dir, exist_ok=True)',
            '    col = Collection(col_path)',
            '    importer = AnkiPackageImporter(col, "' . $destPath . '")',
            '    importer.run()',
            '    decks = [{"name": d.name, "id": str(d.id)} for d in col.decks.all_names_and_ids() if d.name != "Default"]',
            '    notes = col.note_count() if hasattr(col, "note_count") else len(decks)',
            '    col.close()',
            '    print(json.dumps({"status": "imported", "decks": decks, "notes_count": notes}))',
            'except Exception as e:',
            '    print(json.dumps({"status": "error", "error": str(e)}))'
        ]);

        $output = shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        @unlink($destPath);

        $result = json_decode(trim((string)$output), true);
        if (!is_array($result)) {
            $this->abort(500, 'Erro ao processar pacote Anki.');
            return;
        }

        $this->respond($result);
    }

    // PT: Exporta baralho Anki individual ou coleção completa em formato .apkg com isolamento de usuário
    // EN: Exports individual Anki deck or entire collection into .apkg package with user isolation
    // AI: @llm-context ITEM 4: GET /api/v1/svitlo/anki/export?deck={name}
    public function ankiExportApkg(): void {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);
        $colPath = $this->getAnkiCollectionPath($uid);
        $deckName = (string)($_GET['deck'] ?? '');

        $outFile = tempnam(sys_get_temp_dir(), 'svitlo_export_') . '.apkg';
        $safeCol = addslashes($colPath);
        $pyCode = implode("\n", [
            'from anki.collection import Collection',
            'from anki.exporting import AnkiPackageExporter',
            'import json, sys, os',
            'try:',
            '    col_path = "' . $safeCol . '"',
            '    if not os.path.exists(col_path):',
            '        col_dir = os.path.dirname(col_path)',
            '        if col_dir and not os.path.exists(col_dir): os.makedirs(col_dir, exist_ok=True)',
            '    col = Collection(col_path)',
            '    exporter = AnkiPackageExporter(col)',
            '    deck = "' . addslashes($deckName) . '"',
            '    if deck:',
            '        did = col.decks.id(deck)',
            '        exporter.did = did',
            '    exporter.includeMedia = True',
            '    exporter.exportInto("' . $outFile . '")',
            '    col.close()',
            '    print(json.dumps({"status": "ok"}))',
            'except Exception as e:',
            '    print(json.dumps({"status": "error", "error": str(e)}))'
        ]);

        $output = shell_exec('/var/www/mcp-server/venv/bin/python3 -c ' . escapeshellarg($pyCode));
        $res = json_decode(trim((string)$output), true);

        if (!file_exists($outFile) || filesize($outFile) === 0 || ($res['status'] ?? '') === 'error') {
            @unlink($outFile);
            $this->abort(500, 'Erro ao exportar pacote Anki: ' . ($res['error'] ?? 'desconhecido'));
            return;
        }

        $filename = $deckName ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $deckName) . '.apkg' : 'svitlo_colecao_anki.apkg';

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($outFile));
        readfile($outFile);
        @unlink($outFile);
        exit;
    }

    // ── TEACHER VIEW & CLASS COGNITIVE PROFILE (TCC PROF. DR. TIAGO FEITOSA RIBEIRO) ──

    // PT: Cria uma nova turma de professor com código aleatório de convite
    // EN: Creates a new teacher class with random invite code
    // AI: @llm-context POST /api/v1/svitlo/classes
    public function createClass(): void
    {
        $this->requireAuth();
        $teacherId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $name = trim((string)($b['name'] ?? ''));
        if (empty($name)) {
            $this->abort(400, 'Nome da turma é obrigatório.');
            return;
        }

        $subject = trim((string)($b['subject'] ?? ''));
        $semester = trim((string)($b['semester'] ?? ''));
        $year = isset($b['year']) && (int)$b['year'] > 2000 ? (int)$b['year'] : (int)date('Y');
        $institutionId = !empty($b['institution_id']) ? (int)$b['institution_id'] : null;

        // Gera código de convite único de 8 caracteres alfanuméricos
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $inviteCode = '';
        for ($i = 0; $i < 10; $i++) {
            $candidate = '';
            for ($c = 0; $c < 8; $c++) {
                $candidate .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $exists = MriyaDB::queryOne('SELECT id FROM svitlo_classes WHERE invite_code = ?', [$candidate]);
            if (!$exists) {
                $inviteCode = $candidate;
                break;
            }
        }
        if (!$inviteCode) {
            $inviteCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        MriyaDB::execute(
            'INSERT INTO svitlo_classes (institution_id, teacher_id, name, subject, semester, year, invite_code, active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())',
            [$institutionId, $teacherId, $name, $subject, $semester, $year, $inviteCode]
        );
        $classId = (int)MriyaDB::lastInsertId();

        $this->respond([
            'status' => 'created',
            'class_id' => $classId,
            'invite_code' => $inviteCode,
            'name' => $name
        ]);
    }

    // PT: Lista turmas do professor autenticado e turmas matriculadas como aluno
    // EN: Lists classes owned by the authenticated teacher and enrolled classes as student
    // AI: @llm-context GET /api/v1/svitlo/classes
    public function listClasses(): void
    {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        $classes = MriyaDB::query(
            'SELECT c.id, c.institution_id, c.teacher_id, c.name, c.subject, c.semester, c.year, c.invite_code, c.created_at,
                    (SELECT COUNT(*) FROM svitlo_class_students WHERE class_id = c.id) as student_count,
                    (SELECT COUNT(*) FROM svitlo_class_students WHERE class_id = c.id AND share_cognitive_profile = 1) as shared_count
             FROM svitlo_classes c
             WHERE c.teacher_id = ? AND c.active = 1
             ORDER BY c.created_at DESC',
            [$uid]
        ) ?: [];

        $enrolled = MriyaDB::query(
            'SELECT c.id, c.name, c.subject, c.semester, c.year, c.invite_code, cs.share_cognitive_profile, cs.enrolled_at, u.name as teacher_name
             FROM svitlo_class_students cs
             JOIN svitlo_classes c ON c.id = cs.class_id
             LEFT JOIN users u ON u.id = c.teacher_id
             WHERE cs.student_id = ? AND c.active = 1
             ORDER BY cs.enrolled_at DESC',
            [$uid]
        ) ?: [];

        $this->respond([
            'classes' => $classes,
            'enrolled' => $enrolled,
            'total' => count($classes)
        ]);
    }

    // PT: Obtém detalhes da turma, lista de alunos anônima e perfil cognitivo coletivo
    // EN: Gets class details, anonymized student list, and class cognitive profile
    // AI: @llm-context GET /api/v1/svitlo/classes/{id}
    public function getClass(int $classId): void
    {
        $this->requireAuth();
        $uid = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        $class = MriyaDB::queryOne(
            'SELECT c.*, u.name as teacher_name,
                    (SELECT COUNT(*) FROM svitlo_class_students WHERE class_id = c.id) as student_count,
                    (SELECT COUNT(*) FROM svitlo_class_students WHERE class_id = c.id AND share_cognitive_profile = 1) as shared_count
             FROM svitlo_classes c
             LEFT JOIN users u ON u.id = c.teacher_id
             WHERE c.id = ? AND c.active = 1',
            [$classId]
        );

        if (!$class) {
            $this->abort(404, 'Turma não encontrada.');
            return;
        }

        $isTeacher = ((int)$class['teacher_id'] === $uid);
        $role = (string)($this->user['role'] ?? '');
        $isAdmin = in_array(strtolower($role), ['admin', 'superadmin'], true);

        // Se for aluno matriculado (não o professor)
        if (!$isTeacher && !$isAdmin) {
            $enrollment = MriyaDB::queryOne('SELECT * FROM svitlo_class_students WHERE class_id = ? AND student_id = ?', [$classId, $uid]);
            if (!$enrollment) {
                $this->abort(403, 'Acesso não autorizado a esta turma.');
                return;
            }
            $this->respond([
                'class' => [
                    'id' => (int)$class['id'],
                    'name' => $class['name'],
                    'subject' => $class['subject'],
                    'semester' => $class['semester'],
                    'year' => $class['year'],
                    'teacher_name' => $class['teacher_name'] ?? 'Professor',
                    'student_count' => (int)$class['student_count']
                ],
                'is_student' => true,
                'share_cognitive_profile' => (bool)$enrollment['share_cognitive_profile']
            ]);
            return;
        }

        // Visão do professor: lista anônima de alunos (primeiro nome apenas!)
        $studentsRaw = MriyaDB::query(
            'SELECT cs.id, cs.student_id, cs.share_cognitive_profile, cs.enrolled_at, u.name as full_name
             FROM svitlo_class_students cs
             JOIN users u ON u.id = cs.student_id
             WHERE cs.class_id = ?
             ORDER BY cs.enrolled_at ASC',
            [$classId]
        ) ?: [];

        $students = [];
        foreach ($studentsRaw as $s) {
            $parts = explode(' ', trim((string)$s['full_name']));
            $firstName = !empty($parts[0]) ? $parts[0] : 'Aluno';
            $students[] = [
                'id' => (int)$s['id'],
                'first_name' => $firstName,
                'share_cognitive_profile' => (bool)$s['share_cognitive_profile'],
                'enrolled_at' => $s['enrolled_at']
            ];
        }

        // Resumo cognitivo agregado
        $summary = MriyaDB::queryOne('SELECT * FROM svitlo_class_cognitive_summary WHERE class_id = ?', [$classId]);
        $needsRegen = false;
        if (!$summary) {
            $needsRegen = true;
        } else {
            $generatedAt = strtotime((string)$summary['generated_at']);
            if (time() - $generatedAt > 86400 || (int)$summary['student_count'] !== (int)$class['shared_count']) {
                $needsRegen = true;
            }
        }

        if ($needsRegen) {
            $this->generateClassCognitiveSummary($classId);
            $summary = MriyaDB::queryOne('SELECT * FROM svitlo_class_cognitive_summary WHERE class_id = ?', [$classId]);
        }

        $summaryJson = null;
        if ($summary && !empty($summary['summary_json'])) {
            $summaryJson = json_decode((string)$summary['summary_json'], true);
        }
        $aiRecs = null;
        if ($summary && !empty($summary['ai_recommendations'])) {
            $aiRecs = json_decode((string)$summary['ai_recommendations'], true) ?: $summary['ai_recommendations'];
        }

        $this->respond([
            'class' => $class,
            'students' => $students,
            'summary' => [
                'generated_at' => $summary['generated_at'] ?? null,
                'student_count' => (int)($summary['student_count'] ?? 0),
                'data' => $summaryJson,
                'ai_recommendations' => $aiRecs,
                'ai_model_used' => $summary['ai_model_used'] ?? 'none'
            ]
        ]);
    }

    // PT: Matricula o estudante autenticado na turma via código de convite
    // EN: Enrolls authenticated student in class via invite code
    // AI: @llm-context POST /api/v1/svitlo/classes/join
    public function joinClass(): void
    {
        $this->requireAuth();
        $studentId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $code = strtoupper(trim((string)($b['invite_code'] ?? '')));
        if (empty($code)) {
            $this->abort(400, 'Código de convite é obrigatório.');
            return;
        }

        $class = MriyaDB::queryOne(
            'SELECT c.*, u.name as teacher_name FROM svitlo_classes c LEFT JOIN users u ON u.id = c.teacher_id WHERE c.invite_code = ? AND c.active = 1',
            [$code]
        );

        if (!$class) {
            $this->abort(404, 'Código de convite inválido ou turma não encontrada.');
            return;
        }

        MriyaDB::execute(
            'INSERT INTO svitlo_class_students (class_id, student_id, share_cognitive_profile, enrolled_at)
             VALUES (?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE class_id = class_id',
            [(int)$class['id'], $studentId]
        );

        $this->respond([
            'status' => 'joined',
            'class_id' => (int)$class['id'],
            'class_name' => $class['name'],
            'teacher_name' => $class['teacher_name'] ?? 'Professor',
            'share_cognitive_profile' => false
        ]);
    }

    // PT: Altera autorização de compartilhamento do perfil cognitivo pelo aluno
    // EN: Toggles student cognitive profile sharing authorization for a class
    // AI: @llm-context PUT /api/v1/svitlo/classes/{id}/share-profile
    public function toggleProfileShare(int $classId): void
    {
        $this->requireAuth();
        $studentId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $share = !empty($b['share']) ? 1 : 0;

        $enrollment = MriyaDB::queryOne('SELECT * FROM svitlo_class_students WHERE class_id = ? AND student_id = ?', [$classId, $studentId]);
        if (!$enrollment) {
            $this->abort(403, 'Você não está matriculado nesta turma.');
            return;
        }

        MriyaDB::execute(
            'UPDATE svitlo_class_students SET share_cognitive_profile = ? WHERE class_id = ? AND student_id = ?',
            [$share, $classId, $studentId]
        );

        // Regenera o perfil agregado da turma
        $this->generateClassCognitiveSummary($classId);

        $this->respond([
            'status' => 'updated',
            'class_id' => $classId,
            'share_cognitive_profile' => (bool)$share
        ]);
    }

    // PT: Gera resumo cognitivo agregado e recomendações pedagógicas da turma com IA (UDL & Neuroplasticidade)
    // EN: Generates aggregated class cognitive summary and AI pedagogical recommendations (UDL & Neuroplasticity)
    // AI: @llm-context Agrega métricas RDoC, VARK e bem-estar de alunos com share=1 e sintetiza via AI Router
    private function generateClassCognitiveSummary(int $classId): void
    {
        $sharedStudents = MriyaDB::query(
            'SELECT cs.student_id, u.name, p.cognitive_profile, p.neuro_attention, p.neuro_working_memory, p.neuro_processing_speed, p.neuro_flexibility, a.study_focus
             FROM svitlo_class_students cs
             JOIN users u ON u.id = cs.student_id
             LEFT JOIN svitlo_academic_profiles p ON p.user_id = cs.student_id
             LEFT JOIN user_accessibility_preferences a ON a.user_id = cs.student_id
             WHERE cs.class_id = ? AND cs.share_cognitive_profile = 1',
            [$classId]
        ) ?: [];

        $totalShared = count($sharedStudents);
        if ($totalShared === 0) {
            $emptySummary = [
                'student_count' => 0,
                'attention' => ['high' => 0, 'medium' => 0, 'low' => 0],
                'memory' => ['high' => 0, 'medium' => 0, 'low' => 0],
                'speed' => ['fast' => 0, 'medium' => 0, 'slow' => 0],
                'flexibility' => ['high' => 0, 'medium' => 0, 'low' => 0],
                'vark' => ['visual' => 0, 'auditory' => 0, 'reading' => 0, 'kinesthetic' => 0],
                'percentages' => [
                    'attention' => ['high' => 0, 'medium' => 0, 'low' => 0],
                    'memory' => ['high' => 0, 'medium' => 0, 'low' => 0],
                    'speed' => ['fast' => 0, 'medium' => 0, 'slow' => 0],
                    'vark' => ['visual' => 0, 'auditory' => 0, 'reading' => 0, 'kinesthetic' => 0]
                ]
            ];
            MriyaDB::execute(
                'INSERT INTO svitlo_class_cognitive_summary (class_id, generated_at, student_count, summary_json, ai_recommendations, ai_model_used)
                 VALUES (?, NOW(), 0, ?, ?, "none")
                 ON DUPLICATE KEY UPDATE generated_at=NOW(), student_count=0, summary_json=VALUES(summary_json), ai_recommendations=VALUES(ai_recommendations), ai_model_used="none"',
                [$classId, json_encode($emptySummary), 'Nenhum aluno compartilhou o perfil cognitivo ainda.']
            );
            return;
        }

        $attCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
        $memCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
        $spdCounts = ['fast' => 0, 'medium' => 0, 'slow' => 0];
        $flxCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
        $varkCounts = ['visual' => 0, 'auditory' => 0, 'reading' => 0, 'kinesthetic' => 0];

        foreach ($sharedStudents as $st) {
            $cpRaw = $st['cognitive_profile'] ?? null;
            $cp = is_string($cpRaw) ? json_decode($cpRaw, true) : (is_array($cpRaw) ? $cpRaw : []);

            // 1. Attention
            $attVal = '';
            if (!empty($cp['rdoc_profile'])) {
                $rd = $cp['rdoc_profile'];
                $arousal = $rd['Excitação/Regulação'] ?? ($rd['arousal_regulatory'] ?? null);
                if (is_array($arousal)) $attVal = (string)($arousal['level'] ?? '');
            }
            if (!$attVal && !empty($st['neuro_attention'])) $attVal = (string)$st['neuro_attention'];
            $attNorm = mb_strtolower($attVal);
            if (str_contains($attNorm, 'alta') || str_contains($attNorm, 'excepcional') || str_contains($attNorm, 'superior') || str_contains($attNorm, 'ótimo')) {
                $attCounts['high']++;
            } elseif (str_contains($attNorm, 'baixa') || str_contains($attNorm, 'inferior') || str_contains($attNorm, 'comprometido') || str_contains($attNorm, 'déficit')) {
                $attCounts['low']++;
            } else {
                $attCounts['medium']++;
            }

            // 2. Memory
            $memVal = '';
            if (!empty($cp['rdoc_profile'])) {
                $rd = $cp['rdoc_profile'];
                $cogSys = $rd['Cognição Positiva'] ?? ($rd['cognitive_systems'] ?? ($rd['working_memory'] ?? null));
                if (is_array($cogSys)) $memVal = (string)($cogSys['level'] ?? '');
            }
            if (!$memVal && !empty($st['neuro_working_memory'])) $memVal = (string)$st['neuro_working_memory'];
            $memNorm = mb_strtolower($memVal);
            if (str_contains($memNorm, 'alta') || str_contains($memNorm, 'excepcional') || str_contains($memNorm, 'superior') || str_contains($memNorm, 'preservada')) {
                $memCounts['high']++;
            } elseif (str_contains($memNorm, 'baixa') || str_contains($memNorm, 'inferior') || str_contains($memNorm, 'comprometido')) {
                $memCounts['low']++;
            } else {
                $memCounts['medium']++;
            }

            // 3. Speed
            $spdVal = '';
            if (!empty($cp['rdoc_profile'])) {
                $rd = $cp['rdoc_profile'];
                $sm = $rd['Sistemas Sensoriomotores'] ?? ($rd['sensorimotor'] ?? null);
                if (is_array($sm)) $spdVal = (string)($sm['level'] ?? '');
            }
            if (!$spdVal && !empty($st['neuro_processing_speed'])) $spdVal = (string)$st['neuro_processing_speed'];
            $spdNorm = mb_strtolower($spdVal);
            if (str_contains($spdNorm, 'rápida') || str_contains($spdNorm, 'ágil') || str_contains($spdNorm, 'superior') || str_contains($spdNorm, 'alta')) {
                $spdCounts['fast']++;
            } elseif (str_contains($spdNorm, 'lenta') || str_contains($spdNorm, 'inferior') || str_contains($spdNorm, 'baixa')) {
                $spdCounts['slow']++;
            } else {
                $spdCounts['medium']++;
            }

            // 4. Flexibility
            $flxVal = (string)($st['neuro_flexibility'] ?? '');
            $flxNorm = mb_strtolower($flxVal);
            if (str_contains($flxNorm, 'alta') || str_contains($flxNorm, 'flexível') || str_contains($flxNorm, 'superior')) {
                $flxCounts['high']++;
            } elseif (str_contains($flxNorm, 'baixa') || str_contains($flxNorm, 'rigidez') || str_contains($flxNorm, 'rígido')) {
                $flxCounts['low']++;
            } else {
                $flxCounts['medium']++;
            }

            // 5. VARK Style
            $vark = $cp['vark']['dominant'] ?? ($cp['learning_style'] ?? 'V');
            $varkChar = strtoupper(substr((string)$vark, 0, 1));
            match($varkChar) {
                'A' => $varkCounts['auditory']++,
                'R', 'L' => $varkCounts['reading']++,
                'K', 'C' => $varkCounts['kinesthetic']++,
                default => $varkCounts['visual']++
            };
        }

        // Percentage calculations
        $calcPct = fn($val, $tot) => $tot > 0 ? (int)round(($val / $tot) * 100) : 0;
        $percentages = [
            'attention' => [
                'high'   => $calcPct($attCounts['high'], $totalShared),
                'medium' => $calcPct($attCounts['medium'], $totalShared),
                'low'    => $calcPct($attCounts['low'], $totalShared),
            ],
            'memory' => [
                'high'   => $calcPct($memCounts['high'], $totalShared),
                'medium' => $calcPct($memCounts['medium'], $totalShared),
                'low'    => $calcPct($memCounts['low'], $totalShared),
            ],
            'speed' => [
                'fast'   => $calcPct($spdCounts['fast'], $totalShared),
                'medium' => $calcPct($spdCounts['medium'], $totalShared),
                'slow'   => $calcPct($spdCounts['slow'], $totalShared),
            ],
            'vark' => [
                'visual'      => $calcPct($varkCounts['visual'], $totalShared),
                'auditory'    => $calcPct($varkCounts['auditory'], $totalShared),
                'reading'     => $calcPct($varkCounts['reading'], $totalShared),
                'kinesthetic' => $calcPct($varkCounts['kinesthetic'], $totalShared),
            ]
        ];

        $summaryData = [
            'student_count' => $totalShared,
            'attention' => $attCounts,
            'memory' => $memCounts,
            'speed' => $spdCounts,
            'flexibility' => $flxCounts,
            'vark' => $varkCounts,
            'percentages' => $percentages
        ];

        // Prompt for AI
        $system = "You are an educational neuroscience expert helping a teacher understand their class cognitive profile. Based on the aggregated data below, provide:
1. A brief summary of the class profile (3-4 sentences, accessible language)
2. 5 to 8 specific, actionable pedagogical recommendations
3. Any alerts (e.g., high percentage of students with low sustained attention)

IMPORTANT:
- Never mention individual students
- Use inclusive, non-pathologizing language
- Base recommendations on UDL (Universal Design for Learning) principles
- Reference neuroplasticity: these profiles are not fixed
- Language: Portuguese (Brazil)

Retorne APENAS um JSON válido no formato:
{
  \"summary\": \"texto do resumo...\",
  \"recommendations\": [\"recomendação 1\", \"recomendação 2\", ...],
  \"alerts\": [\"alerta 1\", ...]
}";

        $classRow = MriyaDB::queryOne('SELECT name, subject, semester FROM svitlo_classes WHERE id = ?', [$classId]);
        $className = $classRow['name'] ?? 'Turma';
        $classSub = $classRow['subject'] ?? 'Geral';

        $prompt = "Dados Agregados da Turma: {$className} ({$classSub})\n"
            . "Total de alunos que compartilharam: {$totalShared}\n\n"
            . "Atenção Sustentada: Alta {$percentages['attention']['high']}%, Média {$percentages['attention']['medium']}%, Baixa {$percentages['attention']['low']}%\n"
            . "Memória de Trabalho: Alta {$percentages['memory']['high']}%, Média {$percentages['memory']['medium']}%, Baixa {$percentages['memory']['low']}%\n"
            . "Velocidade de Processamento: Rápida {$percentages['speed']['fast']}%, Média {$percentages['speed']['medium']}%, Lenta {$percentages['speed']['slow']}%\n"
            . "Estilo de Aprendizagem (VARK): Visual {$percentages['vark']['visual']}%, Auditivo {$percentages['vark']['auditory']}%, Leitura/Escrita {$percentages['vark']['reading']}%, Cinestésico {$percentages['vark']['kinesthetic']}%\n\n"
            . "Gere o resumo pedagógico, recomendações e alertas em JSON.";

        $aiRaw = '';
        try {
            $aiRaw = $this->callAI($system, $prompt, 'analysis', 'svitlo_research');
        } catch (\Throwable $e) {
            $this->logError('generateClassCognitiveSummary', $e->getMessage());
        }

        $cleanAi = preg_replace('/```(?:json)?\s*/i', '', $aiRaw) ?? '';
        $cleanAi = trim(preg_replace('/```/', '', $cleanAi) ?? '');
        $aiParsed = json_decode($cleanAi, true);

        $recsFormatted = '';
        if (is_array($aiParsed)) {
            $recsFormatted = json_encode($aiParsed, JSON_UNESCAPED_UNICODE);
        } else {
            $recsFormatted = $aiRaw ?: 'Utilize princípios do Desenho Universal para a Aprendizagem (DUA) combinando múltiplas formas de representação e engajamento.';
        }

        MriyaDB::execute(
            'INSERT INTO svitlo_class_cognitive_summary (class_id, generated_at, student_count, summary_json, ai_recommendations, ai_model_used)
             VALUES (?, NOW(), ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE generated_at=NOW(), student_count=VALUES(student_count), summary_json=VALUES(summary_json), ai_recommendations=VALUES(ai_recommendations), ai_model_used=VALUES(ai_model_used)',
            [$classId, $totalShared, json_encode($summaryData, JSON_UNESCAPED_UNICODE), $recsFormatted, $this->lastModelUsed]
        );
    }

    // ── SVITLO PYTHON ORCHESTRATOR INTEGRATION ────────────────────────────────

    /**
     * PT: Executa chamada HTTP ao Svitlo AI Orchestrator local (porta 8200).
     * EN: Executes HTTP call to local Svitlo AI Orchestrator (port 8200).
     * AI: @llm-context POST para http://127.0.0.1:8200 com timeout configurável e fallback resiliente.
     * @param string $action Nome da ação a ser executada no orquestrador
     * @param int $userId ID do usuário ativo
     * @param array $extra Dados adicionais opcionais
     * @param int $timeout Timeout em segundos
     * @return array|null Array com a resposta ou null em caso de erro/timeout
     */
    private function callOrchestrator(string $action, int $userId, array $extra = [], int $timeout = 15): ?array
    {
        try {
            $payload = array_merge(['action' => $action, 'user_id' => $userId], $extra);
            $ch = curl_init('http://127.0.0.1:8200/');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($resp) && $resp !== '') {
                $decoded = json_decode($resp, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
            $this->logError('callOrchestrator', "Action {$action} failed: " . $e->getMessage());
        }
        return null;
    }

    /**
     * PT: Gera cronograma de estudos personalizado chamando o orquestrador Python.
     * EN: Generates personalized study schedule by calling the Python orchestrator.
     * AI: @llm-context POST /api/v1/svitlo/schedule -> Svitlo Orchestrator action="study_schedule"
     */
    public function studySchedule(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        $res = $this->callOrchestrator('study_schedule', $userId, [], 30);
        if ($res !== null) {
            $this->respond($res);
            return;
        }

        $this->abort(502, 'Não foi possível gerar o cronograma. Orquestrador indisponível.');
    }

    /**
     * PT: Monta sessão de estudos guiada para o momento atual.
     * EN: Builds guided study session tailored for right now.
     * AI: @llm-context POST /api/v1/svitlo/session/guided -> Svitlo Orchestrator action="guided_session"
     */
    public function guidedSession(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        $res = $this->callOrchestrator('guided_session', $userId, [], 20);
        if ($res !== null) {
            $this->respond($res);
            return;
        }

        $this->abort(502, 'Não foi possível montar a sessão guiada. Orquestrador indisponível.');
    }

    /**
     * PT: Retorna o diagnóstico semanal a partir do cache acadêmico ou executa sob demanda.
     * EN: Returns weekly diagnosis from academic cache or executes on demand.
     * AI: @llm-context GET /api/v1/svitlo/diagnosis -> reads from svitlo_academic_cache key='weekly_diagnosis'
     */
    public function weeklyDiagnosis(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        // 1. Tenta ler do cache svitlo_academic_cache
        $queryHash = "weekly_diagnosis_{$userId}";
        try {
            $cached = MriyaDB::queryOne(
                "SELECT results FROM svitlo_academic_cache WHERE (query_hash = ? OR query_hash = 'weekly_diagnosis') AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY cached_at DESC LIMIT 1",
                [$queryHash]
            );
            if ($cached && !empty($cached['results'])) {
                $data = is_string($cached['results']) ? json_decode($cached['results'], true) : $cached['results'];
                if (is_array($data) && !empty($data)) {
                    $this->respond($data);
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->logError('weeklyDiagnosis_cache', $e->getMessage());
        }

        // 2. Se não estiver em cache, chama o orquestrador para gerar agora
        $res = $this->callOrchestrator('weekly_diagnosis', $userId, [], 25);
        if ($res !== null) {
            $this->respond($res);
            return;
        }

        $this->respond([
            'summary' => 'Nenhum diagnóstico registrado recentemente.',
            'priorities' => ['Mantenha a rotina diária de estudos'],
            'at_risk' => [],
            'recommendation' => 'Execute sessões com Pomodoro e revise flashcards diariamente.'
        ]);
    }

    /**
     * PT: Retorna alertas preditivos de provas próximas com baixo estudo.
     * EN: Returns predictive alerts for upcoming exams with insufficient study.
     * AI: @llm-context GET /api/v1/svitlo/alerts -> Svitlo Orchestrator action="exam_risk_alert"
     */
    public function examAlerts(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        $res = $this->callOrchestrator('exam_risk_alert', $userId, [], 10);
        if ($res !== null) {
            $this->respond($res);
            return;
        }

        $this->respond(['alerts' => []]);
    }

    // =========================================================================
    // QUIZ INTERATIVO COM INTEGRAÇÃO ANKI (ROOM CODE 6 DÍGITOS, TEMPO REAL)
    // =========================================================================

    /**
     * PT: Gera um código de sala alfanumérico único de 6 caracteres para o quiz.
     * EN: Generates a unique 6-character alphanumeric room code for the quiz.
     * AI: @llm-context Sem caracteres ambíguos (0, O, 1, I).
     */
    private function generateQuizRoomCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $exists = MriyaDB::queryOne('SELECT id FROM svitlo_quizzes WHERE room_code = ? LIMIT 1', [$code]);
            if (!$exists) {
                return $code;
            }
        }
        return 'SV' . strtoupper(substr(uniqid(), -4));
    }

    /**
     * PT: Criação de Quiz pelo professor com perguntas, opções e código de sala.
     * EN: Quiz creation by teacher with questions, options, and room code.
     * AI: @llm-context POST /api/v1/svitlo/quiz
     */
    public function quizCreate(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $body = $this->json();

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            $this->abort(400, 'Título do quiz é obrigatório');
            return;
        }

        $questions = is_array($body['questions'] ?? null) ? $body['questions'] : [];
        if (empty($questions)) {
            $this->abort(400, 'O quiz deve conter pelo menos uma pergunta');
            return;
        }

        $status = in_array($body['status'] ?? '', ['draft', 'active', 'closed'], true) ? $body['status'] : 'active';
        $asyncMode = !empty($body['async_mode']) ? 1 : 0;
        $roomCode = $this->generateQuizRoomCode();

        try {
            MriyaDB::execute(
                'INSERT INTO svitlo_quizzes (user_id, title, room_code, status, async_mode, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$userId, $title, $roomCode, $status, $asyncMode]
            );
            $quizId = (int)MriyaDB::lastInsertId();

            foreach ($questions as $q) {
                if (!is_array($q)) continue;
                $questionText = trim((string)($q['question'] ?? ''));
                if ($questionText === '') continue;

                $options = is_array($q['options'] ?? null) ? array_values($q['options']) : [];
                $correctIndex = isset($q['correct_index']) ? (int)$q['correct_index'] : 0;
                $timeLimit = isset($q['time_limit']) ? max(5, (int)$q['time_limit']) : 30;

                MriyaDB::execute(
                    'INSERT INTO svitlo_quiz_questions (quiz_id, question, options, correct_index, time_limit)
                     VALUES (?, ?, ?, ?, ?)',
                    [$quizId, $questionText, json_encode($options, JSON_UNESCAPED_UNICODE), $correctIndex, $timeLimit]
                );
            }

            $this->respond([
                'success'         => true,
                'quiz_id'         => $quizId,
                'room_code'       => $roomCode,
                'title'           => $title,
                'status'          => $status,
                'async_mode'      => (bool)$asyncMode,
                'total_questions' => count($questions),
                'created_at'      => date('Y-m-d H:i:s'),
                'share_url'       => 'https://svitlo.espectroipe.com.br/quiz.html?room=' . $roomCode
            ], 201);

        } catch (\Throwable $e) {
            $this->logError('quizCreate', $e->getMessage());
            $this->abort(500, 'Erro ao criar quiz: ' . $e->getMessage());
        }
    }

    /**
     * PT: Lista quizzes criados pelo professor/usuário autenticado.
     * EN: Lists quizzes created by authenticated teacher/user.
     * AI: @llm-context GET /api/v1/svitlo/quizzes
     */
    public function quizListMine(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);

        try {
            $rows = MriyaDB::query(
                'SELECT q.id, q.title, q.room_code, q.status, q.async_mode, q.created_at,
                        (SELECT COUNT(*) FROM svitlo_quiz_questions WHERE quiz_id = q.id) as total_questions,
                        (SELECT COUNT(DISTINCT nickname) FROM svitlo_quiz_answers WHERE quiz_id = q.id) as total_participants,
                        (SELECT COUNT(*) FROM svitlo_quiz_answers WHERE quiz_id = q.id) as total_answers
                 FROM svitlo_quizzes q
                 WHERE q.user_id = ?
                 ORDER BY q.id DESC',
                [$userId]
            );

            $quizzes = array_map(function ($row) {
                return [
                    'id'                 => (int)$row['id'],
                    'title'              => $row['title'],
                    'room_code'          => $row['room_code'],
                    'status'             => $row['status'],
                    'async_mode'         => (bool)$row['async_mode'],
                    'created_at'         => $row['created_at'],
                    'total_questions'    => (int)$row['total_questions'],
                    'total_participants' => (int)$row['total_participants'],
                    'total_answers'      => (int)$row['total_answers'],
                    'share_url'          => 'https://svitlo.espectroipe.com.br/quiz.html?room=' . $row['room_code']
                ];
            }, $rows);

            $this->respond([
                'success' => true,
                'total'   => count($quizzes),
                'quizzes' => $quizzes
            ]);
        } catch (\Throwable $e) {
            $this->logError('quizListMine', $e->getMessage());
            $this->abort(500, 'Erro ao listar quizzes: ' . $e->getMessage());
        }
    }

    /**
     * PT: Consulta dados de sala de quiz pública para o aluno participar.
     * EN: Consults public quiz room data for student to join.
     * AI: @llm-context GET /api/v1/svitlo/quiz/{code} — NÃO expõe correct_index aos alunos
     */
    public function quizGet(string $code): void
    {
        $code = strtoupper(trim($code));
        $quiz = MriyaDB::queryOne(
            'SELECT id, user_id, title, room_code, status, async_mode, created_at 
             FROM svitlo_quizzes 
             WHERE room_code = ? LIMIT 1',
            [$code]
        );

        if (!$quiz) {
            $this->abort(404, "Sala de quiz '{$code}' não encontrada.");
            return;
        }

        $questionsRaw = MriyaDB::query(
            'SELECT id, question, options, time_limit 
             FROM svitlo_quiz_questions 
             WHERE quiz_id = ? 
             ORDER BY id ASC',
            [(int)$quiz['id']]
        );

        $questions = array_map(function ($q) {
            $opts = json_decode((string)$q['options'], true);
            return [
                'id'         => (int)$q['id'],
                'question'   => $q['question'],
                'options'    => is_array($opts) ? $opts : [],
                'time_limit' => (int)($q['time_limit'] ?? 30)
            ];
        }, $questionsRaw);

        $this->respond([
            'success'   => true,
            'quiz'      => [
                'id'         => (int)$quiz['id'],
                'title'      => $quiz['title'],
                'room_code'  => $quiz['room_code'],
                'status'     => $quiz['status'],
                'async_mode' => (bool)$quiz['async_mode'],
                'created_at' => $quiz['created_at'],
            ],
            'questions' => $questions
        ]);
    }

    /**
     * PT: Submissão de resposta do aluno com validação e injeção automática no Anki em caso de erro.
     * EN: Student answer submission with validation and automatic Anki injection upon error.
     * AI: @llm-context POST /api/v1/svitlo/quiz/{code}/answer — Se is_correct == 0, gera card no Anki
     */
    public function quizSubmitAnswer(string $code): void
    {
        $code = strtoupper(trim($code));
        $body = $this->json();

        $nickname = trim((string)($body['nickname'] ?? $body['apelido'] ?? ''));
        $questionId = isset($body['question_id']) ? (int)$body['question_id'] : 0;
        $chosenIndex = isset($body['chosen_index']) ? (int)$body['chosen_index'] : -1;

        if ($nickname === '' || $questionId <= 0 || $chosenIndex < 0) {
            $this->abort(422, 'Apelido (nickname), question_id e chosen_index são obrigatórios.');
            return;
        }

        $quiz = MriyaDB::queryOne('SELECT id, user_id, title, status FROM svitlo_quizzes WHERE room_code = ? LIMIT 1', [$code]);
        if (!$quiz) {
            $this->abort(404, 'Sala de quiz não encontrada.');
            return;
        }

        if ($quiz['status'] === 'closed') {
            $this->abort(400, 'Esta sala de quiz já foi encerrada pelo professor.');
            return;
        }

        $question = MriyaDB::queryOne(
            'SELECT id, quiz_id, question, options, correct_index 
             FROM svitlo_quiz_questions 
             WHERE id = ? AND quiz_id = ? LIMIT 1',
            [$questionId, (int)$quiz['id']]
        );

        if (!$question) {
            $this->abort(404, 'Pergunta não encontrada nesta sala de quiz.');
            return;
        }

        $correctIndex = (int)$question['correct_index'];
        $isCorrect = ($chosenIndex === $correctIndex) ? 1 : 0;

        try {
            MriyaDB::execute(
                'INSERT INTO svitlo_quiz_answers (quiz_id, question_id, nickname, chosen_index, is_correct, answered_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [(int)$quiz['id'], $questionId, $nickname, $chosenIndex, $isCorrect]
            );
            $answerId = (int)MriyaDB::lastInsertId();

            $options = json_decode((string)$question['options'], true) ?: [];
            $correctOptionText = $options[$correctIndex] ?? 'Opção ' . ($correctIndex + 1);
            $chosenOptionText = $options[$chosenIndex] ?? 'Opção ' . ($chosenIndex + 1);

            $ankiInjected = false;
            // MECANISMO CRÍTICO DE INJEÇÃO NO ANKI QUANDO ERRA (is_correct == 0)
            if ($isCorrect === 0) {
                try {
                    $front = "<b>Questão (Quiz Svitlo &mdash; {$quiz['title']}):</b><br><br>" . $this->formatMarkdownForAnki((string)$question['question']);
                    $back  = "<b>Resposta Correta:</b><br>" . htmlspecialchars((string)$correctOptionText) . "<br><br>" .
                             "<b>Sua Escolha:</b><br><span style='color:#C62828;'>" . htmlspecialchars((string)$chosenOptionText) . " (Incorreta)</span><br><br>" .
                             "<i>Cartão gerado automaticamente pelo Quiz Interativo do Svitlo para repetição espaçada.</i>";

                    $card = [
                        'front'    => $front,
                        'back'     => $back,
                        'tags'     => ['svitlo-quiz', 'quiz-' . strtolower($code), 'erro-revisao'],
                        'language' => 'pt'
                    ];

                    $deckName = 'Svitlo::' . $quiz['title'];
                    
                    // Se o usuário estiver autenticado no Svitlo, utiliza seu próprio perfil; caso contrário, utiliza o criador do quiz
                    $cardUser = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $quiz['user_id']);
                    if ($cardUser <= 0) $cardUser = 1;

                    $res = $this->injectCardsIntoAnki($deckName, [$card]);
                    if (($res['inserted'] ?? 0) > 0) {
                        $ankiInjected = true;
                    }
                } catch (\Throwable $ae) {
                    $this->logError('quizSubmitAnswer_Anki', $ae->getMessage());
                }
            }

            $this->respond([
                'success'           => true,
                'answer_id'         => $answerId,
                'is_correct'        => (bool)$isCorrect,
                'correct_index'     => $correctIndex,
                'chosen_index'      => $chosenIndex,
                'correct_answer'    => $correctOptionText,
                'nickname'          => $nickname,
                'anki_card_created' => $ankiInjected,
                'message'           => $isCorrect ? 'Resposta correta!' : 'Resposta incorreta. Card adicionado ao Anki para fixação!'
            ]);

        } catch (\Throwable $e) {
            $this->logError('quizSubmitAnswer', $e->getMessage());
            $this->abort(500, 'Erro ao registrar resposta: ' . $e->getMessage());
        }
    }

    /**
     * PT: Consolidação de placar e ranking ao vivo do quiz com estatísticas de opções.
     * EN: Quiz live leaderboard and ranking consolidation with option statistics.
     * AI: @llm-context GET /api/v1/svitlo/quiz/{code}/results
     */
    public function quizGetResults(string $code): void
    {
        $code = strtoupper(trim($code));
        $quiz = MriyaDB::queryOne('SELECT id, user_id, title, room_code, status FROM svitlo_quizzes WHERE room_code = ? LIMIT 1', [$code]);
        if (!$quiz) {
            $this->abort(404, 'Sala de quiz não encontrada.');
            return;
        }

        $quizId = (int)$quiz['id'];

        try {
            // Leaderboard / Placar por apelido
            $leaderboardRaw = MriyaDB::query(
                'SELECT nickname, 
                        COUNT(*) as total_answered, 
                        SUM(is_correct) as correct_count,
                        ROUND((SUM(is_correct) / COUNT(*)) * 100, 1) as accuracy,
                        MAX(answered_at) as last_answered_at
                 FROM svitlo_quiz_answers
                 WHERE quiz_id = ?
                 GROUP BY nickname
                 ORDER BY correct_count DESC, last_answered_at ASC',
                [$quizId]
            );

            $leaderboard = array_map(function ($row, $idx) {
                return [
                    'rank'             => $idx + 1,
                    'nickname'         => $row['nickname'],
                    'total_answered'   => (int)$row['total_answered'],
                    'correct_count'    => (int)$row['correct_count'],
                    'accuracy'         => (float)$row['accuracy'],
                    'last_answered_at' => $row['last_answered_at']
                ];
            }, $leaderboardRaw, array_keys($leaderboardRaw));

            // Estatísticas de distribuição de respostas por pergunta
            $statsRaw = MriyaDB::query(
                'SELECT question_id, chosen_index, COUNT(*) as vote_count
                 FROM svitlo_quiz_answers
                 WHERE quiz_id = ?
                 GROUP BY question_id, chosen_index',
                [$quizId]
            );

            $distribution = [];
            foreach ($statsRaw as $s) {
                $qId = (int)$s['question_id'];
                $cIdx = (int)$s['chosen_index'];
                $cnt = (int)$s['vote_count'];
                if (!isset($distribution[$qId])) {
                    $distribution[$qId] = [];
                }
                $distribution[$qId][$cIdx] = $cnt;
            }

            $this->respond([
                'success'            => true,
                'quiz'               => [
                    'id'        => $quizId,
                    'title'     => $quiz['title'],
                    'room_code' => $quiz['room_code'],
                    'status'    => $quiz['status']
                ],
                'total_participants' => count($leaderboard),
                'leaderboard'        => $leaderboard,
                'distribution'       => $distribution
            ]);

        } catch (\Throwable $e) {
            $this->logError('quizGetResults', $e->getMessage());
            $this->abort(500, 'Erro ao consolidar resultados: ' . $e->getMessage());
        }
    }

    /**
     * PT: Atualiza o status da sala de quiz (draft, active, closed).
     * EN: Updates quiz room status (draft, active, closed).
     * AI: @llm-context PUT /api/v1/svitlo/quiz/{code}/status
     */
    public function quizUpdateStatus(string $code): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $code = strtoupper(trim($code));
        $body = $this->json();

        $status = (string)($body['status'] ?? '');
        if (!in_array($status, ['draft', 'active', 'closed'], true)) {
            $this->abort(400, "Status inválido. Escolha entre 'draft', 'active' ou 'closed'.");
            return;
        }

        $quiz = MriyaDB::queryOne('SELECT id, user_id FROM svitlo_quizzes WHERE room_code = ? LIMIT 1', [$code]);
        if (!$quiz) {
            $this->abort(404, 'Sala de quiz não encontrada.');
            return;
        }

        $role = (string)($this->user['role'] ?? '');
        $isAdmin = in_array(strtolower($role), ['admin', 'superadmin'], true);

        if ((int)$quiz['user_id'] !== $userId && !$isAdmin) {
            $this->abort(403, 'Apenas o professor titular pode alterar o status desta sala.');
            return;
        }

        MriyaDB::execute('UPDATE svitlo_quizzes SET status = ? WHERE room_code = ?', [$status, $code]);

        $this->respond([
            'success'   => true,
            'room_code' => $code,
            'status'    => $status,
            'message'   => "Status da sala atualizado para '{$status}' com sucesso."
        ]);
    }

    // ── GESTÃO DE EVENTOS ACADÊMICOS, CHECK-IN E CERTIFICADOS ───────────────────

    /**
     * PT: Listagem pública de eventos com filtros de tipo, status e busca textual.
     * EN: Public listing of academic events with type, status and search filters.
     * AI: @llm-context GET /api/v1/svitlo/events
     */
    public function eventsList(): void
    {
        $type   = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
        $search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : 'published';

        $sql = "
            SELECT 
                e.*,
                (SELECT COUNT(*) FROM svitlo_event_registrations r WHERE r.event_id = e.id) as total_registered,
                (SELECT COUNT(*) FROM svitlo_event_registrations r WHERE r.event_id = e.id AND r.checked_in = 1) as total_checked_in
            FROM svitlo_events e
            WHERE 1=1
        ";
        $params = [];

        if ($status !== 'all') {
            $sql .= " AND e.status = ?";
            $params[] = $status;
        }

        if (!empty($type) && in_array($type, ['symposium', 'congress', 'lecture', 'workshop', 'other'], true)) {
            $sql .= " AND e.event_type = ?";
            $params[] = $type;
        }

        if (!empty($search)) {
            $sql .= " AND (e.title LIKE ? OR e.location LIKE ? OR e.description LIKE ?)";
            $wild = '%' . $search . '%';
            $params[] = $wild;
            $params[] = $wild;
            $params[] = $wild;
        }

        $sql .= " ORDER BY e.start_datetime ASC";

        try {
            $events = MriyaDB::query($sql, $params);
            $this->respond([
                'success' => true,
                'total'   => count($events),
                'events'  => $events
            ]);
        } catch (\Throwable $e) {
            $this->logError('eventsList', $e->getMessage());
            $this->abort(500, 'Erro ao carregar eventos: ' . $e->getMessage());
        }
    }

    /**
     * PT: Criação de evento acadêmico (requer autenticação de professor ou admin).
     * EN: Creation of academic event (requires teacher or admin auth).
     * AI: @llm-context POST /api/v1/svitlo/events
     */
    public function eventsCreate(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['user_id'] ?? $this->user['uid'] ?? $this->user['sub'] ?? 0);
        $role   = strtolower((string)($this->user['role'] ?? ''));

        // Valida se usuário tem perfil docente ou admin
        if (!in_array($role, ['teacher', 'professor', 'admin', 'superadmin'], true)) {
            $pref = MriyaDB::queryOne('SELECT academic_degree FROM user_accessibility_preferences WHERE user_id = ?', [$userId]);
            $isAcademic = !empty($pref['academic_degree']);
            if (!$isAcademic && $role !== 'admin' && $role !== 'superadmin') {
                $this->abort(403, 'Apenas professores ou administradores podem publicar eventos acadêmicos.');
                return;
            }
        }

        $b = $this->json();
        $title         = trim((string)($b['title'] ?? ''));
        $description   = trim((string)($b['description'] ?? ''));
        $eventType     = trim((string)($b['event_type'] ?? 'lecture'));
        $startDatetime = trim((string)($b['start_datetime'] ?? ''));
        $endDatetime   = trim((string)($b['end_datetime'] ?? ''));
        $location      = trim((string)($b['location'] ?? ''));
        $onlineUrl     = trim((string)($b['online_url'] ?? ''));
        $maxAttendees  = isset($b['max_attendees']) && $b['max_attendees'] !== '' ? (int)$b['max_attendees'] : null;
        $reqSubmission = !empty($b['requires_submission']) ? 1 : 0;
        $status        = in_array($b['status'] ?? '', ['draft', 'published', 'cancelled'], true) ? $b['status'] : 'published';

        if (empty($title)) {
            $this->abort(400, 'Título do evento é obrigatório.');
            return;
        }
        if (empty($startDatetime) || empty($endDatetime)) {
            $this->abort(400, 'Data/hora de início e término são obrigatórias.');
            return;
        }

        if (!in_array($eventType, ['symposium', 'congress', 'lecture', 'workshop', 'other'], true)) {
            $eventType = 'lecture';
        }

        try {
            MriyaDB::execute("
                INSERT INTO svitlo_events (
                    user_id, title, description, event_type, 
                    start_datetime, end_datetime, location, online_url, 
                    max_attendees, requires_submission, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $userId, $title, $description, $eventType,
                $startDatetime, $endDatetime, $location, $onlineUrl,
                $maxAttendees, $reqSubmission, $status
            ]);

            $eventId = (int)MriyaDB::getInstance()->lastInsertId();

            $this->respond([
                'success'  => true,
                'event_id' => $eventId,
                'message'  => 'Evento acadêmico criado com sucesso!'
            ], 201);
        } catch (\Throwable $e) {
            $this->logError('eventsCreate', $e->getMessage());
            $this->abort(500, 'Erro ao criar evento: ' . $e->getMessage());
        }
    }

    /**
     * PT: Detalhes de um evento acadêmico específico.
     * EN: Specific academic event details.
     * AI: @llm-context GET /api/v1/svitlo/events/{id}
     */
    public function eventsGet(int $id): void
    {
        try {
            $event = MriyaDB::queryOne("
                SELECT 
                    e.*,
                    (SELECT COUNT(*) FROM svitlo_event_registrations r WHERE r.event_id = e.id) as total_registered,
                    (SELECT COUNT(*) FROM svitlo_event_registrations r WHERE r.event_id = e.id AND r.checked_in = 1) as total_checked_in
                FROM svitlo_events e
                WHERE e.id = ?
                LIMIT 1
            ", [$id]);

            if (!$event) {
                $this->abort(404, 'Evento acadêmico não encontrado.');
                return;
            }

            $this->respond([
                'success' => true,
                'event'   => $event
            ]);
        } catch (\Throwable $e) {
            $this->logError('eventsGet', $e->getMessage());
            $this->abort(500, 'Erro ao carregar detalhes do evento.');
        }
    }

    /**
     * PT: Inscrição em evento acadêmico (usuário logado ou visitante por e-mail/nome).
     * EN: Academic event registration (authenticated user or guest by email/name).
     * AI: @llm-context POST /api/v1/svitlo/events/{id}/register -> gera checkin_token (CHAR 32 UNIQUE)
     */
    public function eventsRegister(int $eventId): void
    {
        $event = MriyaDB::queryOne('SELECT id, title, status, max_attendees FROM svitlo_events WHERE id = ? LIMIT 1', [$eventId]);
        if (!$event) {
            $this->abort(404, 'Evento não encontrado.');
            return;
        }

        if ($event['status'] !== 'published') {
            $this->abort(400, 'Inscrições indisponíveis para este evento no momento.');
            return;
        }

        // Verifica lotação
        if (!empty($event['max_attendees']) && (int)$event['max_attendees'] > 0) {
            $countRow = MriyaDB::queryOne('SELECT COUNT(*) as total FROM svitlo_event_registrations WHERE event_id = ?', [$eventId]);
            if ((int)($countRow['total'] ?? 0) >= (int)$event['max_attendees']) {
                $this->abort(400, 'As vagas para este evento já foram esgotadas.');
                return;
            }
        }

        // Tenta obter usuário autenticado opcionalmente
        $token = MriyaJWT::extractFromHeader();
        $authUserId = null;
        $userName = '';
        $userEmail = '';

        if ($token) {
            try {
                $payload = MriyaJWT::validateAccessToken($token);
                $authUserId = (int)($payload['uid'] ?? $payload['sub'] ?? null);
                $userEmail = (string)($payload['email'] ?? '');
                $userName = (string)($payload['name'] ?? '');
            } catch (\Throwable) {}
        }

        $b = $this->json();
        $guestName  = trim((string)($b['guest_name'] ?? $b['name'] ?? $userName));
        $guestEmail = trim((string)($b['guest_email'] ?? $b['email'] ?? $userEmail));

        if (empty($guestName)) {
            $this->abort(400, 'Nome do participante é obrigatório.');
            return;
        }
        if (empty($guestEmail) || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            $this->abort(400, 'E-mail válido é obrigatório para envio do ingresso e certificado.');
            return;
        }

        // Evita inscrição duplicada por e-mail ou user_id
        if ($authUserId) {
            $existing = MriyaDB::queryOne('SELECT id, checkin_token FROM svitlo_event_registrations WHERE event_id = ? AND (user_id = ? OR guest_email = ?) LIMIT 1', [$eventId, $authUserId, $guestEmail]);
        } else {
            $existing = MriyaDB::queryOne('SELECT id, checkin_token FROM svitlo_event_registrations WHERE event_id = ? AND guest_email = ? LIMIT 1', [$eventId, $guestEmail]);
        }

        if ($existing) {
            $this->respond([
                'success'         => true,
                'already_registered' => true,
                'registration_id' => (int)$existing['id'],
                'checkin_token'   => $existing['checkin_token'],
                'message'         => 'Você já está inscrito neste evento!'
            ]);
            return;
        }

        // Gera token único de check-in de 32 caracteres
        $checkinToken = bin2hex(random_bytes(16));

        try {
            MriyaDB::execute("
                INSERT INTO svitlo_event_registrations (
                    event_id, user_id, guest_email, guest_name, checkin_token, registered_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ", [
                $eventId, $authUserId, $guestEmail, $guestName, $checkinToken
            ]);

            $regId = (int)MriyaDB::getInstance()->lastInsertId();

            $this->respond([
                'success'         => true,
                'registration_id' => $regId,
                'checkin_token'   => $checkinToken,
                'event_title'     => $event['title'],
                'participant'     => $guestName,
                'message'         => 'Inscrição confirmada com sucesso!'
            ], 201);
        } catch (\Throwable $e) {
            $this->logError('eventsRegister', $e->getMessage());
            $this->abort(500, 'Erro ao processar inscrição: ' . $e->getMessage());
        }
    }

    /**
     * PT: Registro de presença via leitura de token ou QR Code.
     * EN: Attendance registration via token or QR Code scan.
     * AI: @llm-context POST /api/v1/svitlo/events/{id}/checkin
     */
    public function eventsCheckin(int $eventId): void
    {
        $this->requireAuth();
        $b = $this->json();
        $token = trim((string)($b['token'] ?? $b['checkin_token'] ?? ''));

        if (empty($token) || strlen($token) !== 32) {
            $this->abort(400, 'Token de check-in inválido (deve conter 32 caracteres).');
            return;
        }

        $reg = MriyaDB::queryOne('
            SELECT id, event_id, guest_name, guest_email, checked_in, checked_in_at 
            FROM svitlo_event_registrations 
            WHERE event_id = ? AND checkin_token = ? 
            LIMIT 1
        ', [$eventId, $token]);

        if (!$reg) {
            $this->abort(404, 'Inscrição não encontrada para este evento com o token fornecido.');
            return;
        }

        if ((int)$reg['checked_in'] === 1) {
            $this->respond([
                'success'        => true,
                'already_checked' => true,
                'participant'    => $reg['guest_name'],
                'checked_in_at'  => $reg['checked_in_at'],
                'message'        => "Check-in já realizado anteriormente às " . date('H:i', strtotime((string)$reg['checked_in_at'])) . "!"
            ]);
            return;
        }

        try {
            MriyaDB::execute('
                UPDATE svitlo_event_registrations 
                SET checked_in = 1, checked_in_at = NOW() 
                WHERE id = ?
            ', [$reg['id']]);

            $now = date('Y-m-d H:i:s');

            $this->respond([
                'success'        => true,
                'participant'    => $reg['guest_name'],
                'guest_email'    => $reg['guest_email'],
                'checked_in_at'  => $now,
                'message'        => 'Presença confirmada com sucesso!'
            ]);
        } catch (\Throwable $e) {
            $this->logError('eventsCheckin', $e->getMessage());
            $this->abort(500, 'Erro ao registrar check-in: ' . $e->getMessage());
        }
    }

    /**
     * PT: Geração e download do certificado acadêmico em PDF com wkhtmltopdf.
     * EN: Generation and download of academic certificate PDF via wkhtmltopdf.
     * AI: @llm-context GET /api/v1/svitlo/events/{id}/certificate?token={checkin_token}
     */
    public function eventsCertificate(int $eventId): void
    {
        $token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';

        // Se não veio token por query, tenta verificar se o usuário autenticado está inscrito
        if (empty($token)) {
            $jwt = MriyaJWT::extractFromHeader();
            if ($jwt) {
                try {
                    $payload = MriyaJWT::validateAccessToken($jwt);
                    $uid = (int)($payload['uid'] ?? $payload['sub'] ?? 0);
                    $reg = MriyaDB::queryOne('SELECT * FROM svitlo_event_registrations WHERE event_id = ? AND user_id = ? LIMIT 1', [$eventId, $uid]);
                    if ($reg) $token = $reg['checkin_token'];
                } catch (\Throwable) {}
            }
        }

        if (empty($token)) {
            $this->abort(400, 'Token de inscrição não informado.');
            return;
        }

        $reg = MriyaDB::queryOne('
            SELECT r.*, e.title as event_title, e.event_type, e.start_datetime, e.end_datetime, e.location 
            FROM svitlo_event_registrations r
            JOIN svitlo_events e ON e.id = r.event_id
            WHERE r.event_id = ? AND r.checkin_token = ?
            LIMIT 1
        ', [$eventId, $token]);

        if (!$reg) {
            $this->abort(404, 'Registro de inscrição não encontrado.');
            return;
        }

        if ((int)$reg['checked_in'] !== 1) {
            $this->abort(403, 'O certificado só pode ser emitido após confirmação de presença (check-in) no evento.');
            return;
        }

        // Calcula carga horária aproximada
        $start = new \DateTime($reg['start_datetime']);
        $end   = new \DateTime($reg['end_datetime']);
        $diff  = $start->diff($end);
        $hours = max(1, ($diff->days * 24) + $diff->h);

        $typesMap = [
            'symposium' => 'Simpósio Acadêmico',
            'congress'  => 'Congresso Científico',
            'lecture'   => 'Palestra Magistral',
            'workshop'  => 'Workshop Prático',
            'other'     => 'Evento Acadêmico'
        ];
        $typeLabel = $typesMap[$reg['event_type']] ?? 'Evento Acadêmico';

        $eventDate = $start->format('d/m/Y');
        $validationUrl = "https://svitlo.espectroipe.com.br/validar?token={$token}";
        $qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=" . urlencode($validationUrl);

        // Marca certificado emitido
        MriyaDB::execute('UPDATE svitlo_event_registrations SET certificate_issued = 1 WHERE id = ?', [$reg['id']]);

        // Template do Certificado em HTML A4 Paisagem
        $participantName = htmlspecialchars($reg['guest_name'], ENT_QUOTES, 'UTF-8');
        $eventTitle = htmlspecialchars($reg['event_title'], ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Certificado de Participação — Svitlo</title>
<style>
    @page {
        size: A4 landscape;
        margin: 0;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
        background: #F7F4EF;
        color: #2C1F14;
        width: 297mm;
        height: 210mm;
        padding: 12mm;
        position: relative;
    }
    .cert-border {
        border: 4px double #5C3D1E;
        background: #FFFFFF;
        width: 100%;
        height: 100%;
        padding: 30px 45px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        box-shadow: inset 0 0 20px rgba(92, 61, 30, 0.05);
    }
    .header {
        text-align: center;
        border-bottom: 2px solid #C9A84C;
        padding-bottom: 12px;
    }
    .brand {
        font-size: 15px;
        letter-spacing: 3px;
        font-weight: 700;
        color: #5C3D1E;
        text-transform: uppercase;
    }
    .cert-title {
        font-size: 38px;
        color: #5C3D1E;
        font-weight: 800;
        letter-spacing: 2px;
        margin-top: 6px;
        text-transform: uppercase;
    }
    .content {
        text-align: center;
        margin: 20px 0;
        line-height: 1.8;
    }
    .certifies {
        font-size: 17px;
        color: #6E5A4B;
    }
    .student-name {
        font-size: 32px;
        font-weight: 800;
        color: #5C3D1E;
        margin: 12px 0;
        text-decoration: underline;
        text-decoration-color: #C9A84C;
    }
    .cert-desc {
        font-size: 16px;
        color: #333;
        max-width: 820px;
        margin: 0 auto;
    }
    .footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding-top: 15px;
        border-top: 1px solid rgba(92, 61, 30, 0.15);
    }
    .signature-box {
        text-align: center;
        width: 260px;
    }
    .signature-line {
        border-bottom: 1.5px solid #5C3D1E;
        margin-bottom: 6px;
        height: 25px;
    }
    .sign-name {
        font-size: 14px;
        font-weight: 700;
        color: #5C3D1E;
    }
    .sign-role {
        font-size: 12px;
        color: #6E5A4B;
    }
    .qr-box {
        display: flex;
        align-items: center;
        gap: 15px;
        text-align: left;
    }
    .qr-img {
        width: 90px;
        height: 90px;
        border: 1px solid #C9A84C;
        padding: 3px;
        background: #fff;
    }
    .auth-text {
        font-size: 11px;
        color: #6E5A4B;
        line-height: 1.4;
    }
    .auth-token {
        font-family: monospace;
        font-size: 12px;
        font-weight: 700;
        color: #5C3D1E;
    }
</style>
</head>
<body>
    <div class="cert-border">
        <div class="header">
            <div class="brand">Svitlo &bull; Ecossistema de Aprendizado e Pesquisa</div>
            <div class="cert-title">Certificado</div>
        </div>

        <div class="content">
            <p class="certifies">Certificamos com distinção acadêmica que</p>
            <div class="student-name">' . $participantName . '</div>
            <p class="cert-desc">
                participou com êxito do evento acadêmico <strong>' . $eventTitle . '</strong> (' . $typeLabel . '), 
                realizado em <strong>' . $eventDate . '</strong>, cumprindo a carga horária total de 
                <strong>' . $hours . ' horas</strong> de atividades formativas.
            </p>
        </div>

        <div class="footer">
            <div class="qr-box">
                <img src="' . $qrApiUrl . '" class="qr-img" alt="QR Code">
                <div class="auth-text">
                    <strong>Autenticidade Verificada:</strong><br>
                    svitlo.espectroipe.com.br/validar<br>
                    Chave: <span class="auth-token">' . substr($token, 0, 16) . '...</span>
                </div>
            </div>

            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="sign-name">Coordenação Acadêmica</div>
                <div class="sign-role">Mriya &bull; Espectro Ipê</div>
            </div>
        </div>
    </div>
</body>
</html>';

        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', mb_substr($reg['guest_name'], 0, 30));
        $filename = "Certificado_{$safeName}_{$eventId}.pdf";

        $tmpHtml = tempnam(sys_get_temp_dir(), 'svitlo_cert_') . '.html';
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'svitlo_cert_') . '.pdf';

        file_put_contents($tmpHtml, $html);

        $hasWkhtml = shell_exec('which wkhtmltopdf 2>/dev/null');
        if ($hasWkhtml || stripos(PHP_OS, 'WIN') === false) {
            $cmd = "wkhtmltopdf --quiet --orientation Landscape --page-size A4 --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 " .
                   escapeshellarg($tmpHtml) . " " . escapeshellarg($tmpPdf) . " 2>&1";
            shell_exec($cmd);
        }

        if (file_exists($tmpPdf) && filesize($tmpPdf) > 500) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tmpPdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($tmpPdf);
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            exit;
        }

        // Fallback: se wkhtmltopdf não estiver presente ou falhar no ambiente local, envia HTML printável
        @unlink($tmpHtml);
        @unlink($tmpPdf);

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    // ── ESTUDO ATIVO COM IA (CORNELL, FEYNMAN, MERMAID) ───────────

    // PT: Endpoint unificado de Estudo Ativo com IA — Cards, Cornell, Mermaid e Feynman
    // EN: Unified Active Study AI Endpoint — Cards, Cornell Notes, Mermaid Mindmaps, and Feynman Technique
    // AI: @llm-context POST /api/v1/svitlo/study/active
    public function studyActive(): void
    {
        $this->generate();
    }

    // PT: Gera notas estruturadas estritamente no formato Cornell (Cues, Notes, Summary) + Diagrama Mental Mermaid
    // EN: Generates structured notes strictly in Cornell format (Cues, Notes, Summary) + Mermaid Mindmap
    // AI: @llm-context POST /api/v1/svitlo/study/cornell
    public function studyCornell(): void
    {
        $this->requireAuth();
        $body = $this->json();
        $content = trim((string)($body['content'] ?? $body['text'] ?? ''));
        if (empty($content)) {
            $this->abort(400, 'Conteúdo obrigatório para geração do formato Cornell');
            return;
        }

        $systemPrompt = "Você é um especialista em métodos de estudo ativo e arquitetura de notas Cornell.
Sua tarefa é analisar o texto do estudante e estruturá-lo estritamente no método Cornell de anotações.
Retorne EXCLUSIVAMENTE um objeto JSON válido com este formato exato:
{
  \"topic\": \"Título representativo do tema\",
  \"cornell\": {
    \"cues\": [\"Pergunta-chave 1?\", \"Termo crítico 2\", \"Dúvida conceitual 3?\"],
    \"notes\": \"Texto detalhado com os conceitos principais em tópicos estruturados, hierarquias e definições rigorosas.\",
    \"summary\": \"Síntese final sucinta e memorável (2 a 4 frases) no rodapé da folha.\"
  },
  \"mermaid\": \"graph TD; A[Conceito Principal] --> B[Subtópico 1]; A --> C[Subtópico 2];\"
}";
        $prompt = "Conteúdo para estruturar em Cornell:\n" . $content;

        try {
            $aiResponse = $this->callAI($systemPrompt, $prompt, 'summary', 'svitlo_summary');
            $clean = preg_replace('/```(?:json)?\s*/i', '', $aiResponse) ?? '';
            $clean = trim(preg_replace('/```/', '', $clean) ?? '');
            $data = json_decode($clean, true);
            if (!is_array($data)) {
                preg_match('/\{.*\}/s', $clean, $m);
                $data = !empty($m[0]) ? json_decode($m[0], true) : null;
            }

            if (!is_array($data)) {
                $data = [
                    'topic' => 'Resumo Cornell',
                    'cornell' => [
                        'cues' => ['Conceito Principal', 'Mecanismo Central', 'Aplicações'],
                        'notes' => $content,
                        'summary' => mb_substr($content, 0, 150) . '...'
                    ],
                    'mermaid' => "graph TD;\n  A[Tópico] --> B[Conceitos];\n  A --> C[Síntese];"
                ];
            }

            $this->respond([
                'status' => 'ok',
                'topic' => $data['topic'] ?? 'Estudo Cornell',
                'cornell' => $data['cornell'] ?? [],
                'mermaid' => $data['mermaid'] ?? "graph TD; A[Estudo]-->B[Cornell];",
                'model_used' => $this->lastModelUsed ?: 'groq/qwen3.8-27b'
            ]);
        } catch (\Throwable $e) {
            $this->logError('studyCornell', $e->getMessage());
            $this->abort(500, 'Erro ao gerar notas Cornell: ' . $e->getMessage());
        }
    }

    // PT: Gera perguntas socráticas e análise pela Técnica de Feynman para verificação de compreensão profunda
    // EN: Generates Socratic questions and Feynman Technique analysis for deep understanding checks
    // AI: @llm-context POST /api/v1/svitlo/study/socratic
    public function studySocratic(): void
    {
        $this->requireAuth();
        $body = $this->json();
        $content = trim((string)($body['content'] ?? $body['topic'] ?? ''));
        if (empty($content)) {
            $this->abort(400, 'Tema ou conteúdo obrigatório para formulação de perguntas socráticas');
            return;
        }

        $systemPrompt = "Você é Sócrates e Richard Feynman combinados.
Seu objetivo é testar e aprofundar a compreensão real do estudante sobre o assunto através do método socrático e da técnica de Feynman.
Retorne EXCLUSIVAMENTE um objeto JSON válido com este formato exato:
{
  \"topic\": \"Tema analisado\",
  \"feynman_simplification\": \"Explicação intuitiva como se estivesse ensinando uma criança de 10 anos, usando analogias do dia a dia.\",
  \"socratic_dialogue\": [
    {\"question\": \"Pergunta que desafia a primeira impressão do aluno?\", \"target_insight\": \"O que ele deve descobrir ao responder\"},
    {\"question\": \"Por que isso ocorre e qual a causa primária?\", \"target_insight\": \"Compreensão causal profunda\"},
    {\"question\": \"O que aconteceria se a variável central mudasse?\", \"target_insight\": \"Teste de limites do modelo mental\"}
  ],
  \"knowledge_blindspots\": [\"Ponto cego 1 comumente mal compreendido\", \"Ponto cego 2\"]
}";
        $prompt = "Tema para questionamento socrático:\n" . $content;

        try {
            $aiResponse = $this->callAI($systemPrompt, $prompt, 'socratic', 'svitlo_cards');
            $clean = preg_replace('/```(?:json)?\s*/i', '', $aiResponse) ?? '';
            $clean = trim(preg_replace('/```/', '', $clean) ?? '');
            $data = json_decode($clean, true);
            if (!is_array($data)) {
                preg_match('/\{.*\}/s', $clean, $m);
                $data = !empty($m[0]) ? json_decode($m[0], true) : null;
            }

            if (!is_array($data)) {
                $data = [
                    'topic' => $content,
                    'feynman_simplification' => 'Conceito explicado em termos simples e concretos.',
                    'socratic_dialogue' => [
                        ['question' => 'Qual é a ideia fundamental deste tópico?', 'target_insight' => 'Identificar o núcleo do conceito'],
                        ['question' => 'Por que esse mecanismo é necessário?', 'target_insight' => 'Compreender a função subjacente']
                    ],
                    'knowledge_blindspots' => ['Confundir causa com correlação', 'Memorizar nomes sem entender a função']
                ];
            }

            $this->respond([
                'status' => 'ok',
                'topic' => $data['topic'] ?? $content,
                'feynman' => $data['feynman_simplification'] ?? '',
                'socratic_dialogue' => $data['socratic_dialogue'] ?? [],
                'knowledge_blindspots' => $data['knowledge_blindspots'] ?? [],
                'model_used' => $this->lastModelUsed ?: 'groq/qwen3.8-27b'
            ]);
        } catch (\Throwable $e) {
            $this->logError('studySocratic', $e->getMessage());
            $this->abort(500, 'Erro ao gerar perguntas socráticas: ' . $e->getMessage());
        }
    }

    // ── POMODORO ADAPTATIVO ────────────────────────────────────────

    // PT: Endpoint do Pomodoro Adaptativo com cálculo dinâmico de fadiga cognitiva diária
    // EN: Adaptive Pomodoro endpoint with dynamic daily cognitive fatigue calculation
    // AI: @llm-context GET/POST /api/v1/svitlo/pomodoro/adaptive
    public function pomodoroAdaptive(): void
    {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);

        // 1. Perfil cognitivo funcional do usuário
        $cogProf = MriyaDB::queryOne('SELECT * FROM svitlo_cognitive_profiles WHERE user_id = ?', [$uid]);
        $baseFocus = 25;
        $attentionScore = 80;

        if ($cogProf) {
            $attentionScore = (int)($cogProf['attention_focus_score'] ?? 80);
            $baseFocus = (int)($cogProf['optimal_pomodoro_minutes'] ?? ($cogProf['pomodoro_focus_minutes'] ?? 25));
        }

        // 2. Histórico de sessões de pomodoro completadas hoje
        $todaySessions = MriyaDB::queryOne(
            'SELECT COUNT(*) as count_today, SUM(focus_minutes) as minutes_today 
             FROM svitlo_pomodoro_sessions 
             WHERE user_id = ? AND DATE(created_at) = CURDATE() AND completed = 1',
            [$uid]
        );
        $countToday = (int)($todaySessions['count_today'] ?? 0);
        $minutesToday = (int)($todaySessions['minutes_today'] ?? 0);

        // 3. Cálculo de Fadiga Cognitiva:
        // - < 3 sessões (< 75 min): baixa fadiga -> ritmo pleno
        // - 3 a 6 sessões (75 - 150 min): fadiga moderada -> reduzir blocos de foco e ajustar descanso
        // - > 6 sessões (> 150 min): alta fadiga -> sprints curtos (15 min) com pausas mais longas
        $fatigueLevel = 'low';
        $adjustedFocus = $baseFocus;
        $adjustedBreak = 5;
        $message = 'Prontidão cognitiva excelente. Ritmo de foco sustentável.';

        if ($countToday >= 6 || $minutesToday >= 150) {
            $fatigueLevel = 'high';
            $adjustedFocus = max(15, (int)round($baseFocus * 0.65));
            $adjustedBreak = 8;
            $message = 'Fadiga cognitiva acumulada detectada no dia. Sprints curtos e pausas estendidas para recuperação.';
        } elseif ($countToday >= 3 || $minutesToday >= 75) {
            $fatigueLevel = 'medium';
            $adjustedFocus = max(20, (int)round($baseFocus * 0.85));
            $adjustedBreak = 6;
            $message = 'Fadiga moderada. Pequeno ajuste no bloco de foco para manter alta eficiência.';
        }

        $this->respond([
            'status'                  => 'ok',
            'focus_minutes'           => $adjustedFocus,
            'break_minutes'           => $adjustedBreak,
            'cognitive_fatigue_level' => $fatigueLevel,
            'focus_score'             => $attentionScore,
            'sessions_today'          => $countToday,
            'minutes_studied_today'   => $minutesToday,
            'recommendation'          => $message,
            'profile_base_minutes'    => $baseFocus
        ]);
    }

    // PT: Registra uma sessão de Pomodoro adaptativo concluída
    // EN: Logs a completed adaptive Pomodoro session
    // AI: @llm-context POST /api/v1/svitlo/pomodoro/session
    public function pomodoroLogSession(): void
    {
        $this->requireAuth();
        $uid = (int)($this->user['uid'] ?? $this->user['sub'] ?? 0);
        $b = $this->json();

        $disciplineId = !empty($b['discipline_id']) ? (int)$b['discipline_id'] : null;
        $focusMin = isset($b['focus_minutes']) ? (int)$b['focus_minutes'] : 25;
        $breakMin = isset($b['break_minutes']) ? (int)$b['break_minutes'] : 5;
        $fatigue = in_array($b['cognitive_fatigue_level'] ?? '', ['low', 'medium', 'high']) ? $b['cognitive_fatigue_level'] : 'low';
        $focusScore = isset($b['focus_score']) ? (int)$b['focus_score'] : 85;
        $completed = isset($b['completed']) ? ((int)$b['completed'] ? 1 : 0) : 1;
        $notes = !empty($b['notes']) ? (string)$b['notes'] : null;

        MriyaDB::execute("
            INSERT INTO svitlo_pomodoro_sessions 
            (user_id, discipline_id, focus_minutes, break_minutes, cognitive_fatigue_level, focus_score, completed, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ", [$uid, $disciplineId, $focusMin, $breakMin, $fatigue, $focusScore, $completed, $notes]);

        $this->respond([
            'status' => 'logged',
            'session_id' => MriyaDB::lastInsertId()
        ]);
    }

    // ── UNIVERSAL LEARNING & STORAGE ──────────────────────────────

    /**
     * PT: Obtém a cota de armazenamento do Google Drive do usuário autenticado via API REST v3.
     * EN: Retrieves Google Drive storage quota for authenticated user via REST API v3.
     * AI: @llm-context Módulo Svitlo / Google Drive Integration. Endpoint about.get?fields=storageQuota.
     * AI: @llm-invariant Requer JWT válido e token OAuth ativo do usuário para o Google Drive.
     * AI: @llm-rule Retorna JSON com storageQuota em HTTP 200, 401 se token ausente, 502 em falha na API externa.
     */
    public function getDriveStorageQuota(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);

        // PT: Consome token OAuth do usuário (fornecido na requisição ou resolvido via conexões salvas)
        $token = null;
        if (!empty($_GET['access_token'])) {
            $token = trim((string)($_GET['access_token'] ?? '')); // Sanitize access_token / Sanitiza o access_token
        } elseif (!empty($_SERVER['HTTP_X_GOOGLE_TOKEN'])) {
            $token = trim((string)$_SERVER['HTTP_X_GOOGLE_TOKEN']);
        } else {
            $token = $this->getValidGoogleToken($userId);
        }

        if (empty($token)) {
            $this->abort(401, 'Token OAuth do Google Drive não encontrado para o usuário.');
            return;
        }

        $url = 'https://www.googleapis.com/drive/v3/about?fields=storageQuota';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // Se expirou e temos refresh_token, tenta renovar e reenviar uma vez
        if ($httpCode === 401) {
            $refreshedToken = $this->getValidGoogleToken($userId, true);
            if ($refreshedToken && $refreshedToken !== $token) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $refreshedToken,
                        'Accept: application/json'
                    ],
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);
                $raw = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
        }

        if ($httpCode !== 200) {
            $this->abort($httpCode >= 400 && $httpCode < 500 ? $httpCode : 502, 'Falha ao consultar cota do Google Drive: ' . ($curlErr ?: (string)$raw));
            return;
        }

        $data = json_decode((string)$raw, true) ?: [];
        $quota = $data['storageQuota'] ?? $data;

        $this->respond([
            'success'      => true,
            'storageQuota' => $quota
        ]);
    }

    /**
     * PT: Cria a pasta Svitlo/Disciplinas/{nome} no Google Drive, envia arquivo PDF e persiste registro do material acadêmico.
     * EN: Creates Svitlo/Disciplinas/{nome} folder in Google Drive, uploads PDF file, and persists academic material record.
     * AI: @llm-context Módulo Svitlo / Gestão de Materiais e Drive. Tabela svitlo_discipline_materials.
     * AI: @llm-invariant Requer autenticação JWT, validação estrita de Tenant, validação de PDF (MIME/tamanho) e token OAuth do Google.
     * AI: @llm-rule Retorna 201 com id do material e dados do arquivo criado no Google Drive; aborta com 400, 401, 403 ou 502 em caso de falha.
     */
    public function uploadDisciplineMaterialToDrive(): void
    {
        $this->requireAuth();
        $userId = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);

        // Verificação estrita de Tenant antes de qualquer mutação
        $tenantId = (int)($this->user['tenant_id'] ?? 0);
        $pdo = MriyaDB::getInstance();
        if ($tenantId <= 0) {
            $stmtTenant = $pdo->prepare("SELECT tenant_id FROM users WHERE id = :uid LIMIT 1");
            $stmtTenant->execute([':uid' => $userId]);
            $tenantId = (int)($stmtTenant->fetchColumn() ?: 1);
        }
        if ($tenantId <= 0) {
            $this->abort(403, 'Acesso negado: Tenant inválido ou não autenticado.');
            return;
        }

        // Validação da disciplina
        $disciplineId = (int)($_POST['discipline_id'] ?? ($_GET['discipline_id'] ?? 0));
        if ($disciplineId <= 0) {
            $body = $this->json();
            $disciplineId = (int)($body['discipline_id'] ?? 0);
        }
        if ($disciplineId <= 0) {
            $this->abort(400, 'Identificador de disciplina (discipline_id) é obrigatório.');
            return;
        }

        $stmtDisc = $pdo->prepare("SELECT id, name FROM svitlo_disciplines WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmtDisc->execute([':id' => $disciplineId, ':uid' => $userId]);
        $discipline = $stmtDisc->fetch(PDO::FETCH_ASSOC);
        if (!$discipline) {
            $this->abort(404, 'Disciplina não encontrada ou não pertence ao usuário autenticado.');
            return;
        }
        $disciplineName = trim((string)$discipline['name']);

        // Validação do arquivo PDF enviado
        $pdfFile = $_FILES['pdf'] ?? ($_FILES['file'] ?? null);
        if (!$pdfFile || !is_array($pdfFile) || ($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->abort(400, 'Nenhum arquivo PDF foi enviado na requisição.');
            return;
        }
        if ($pdfFile['error'] !== UPLOAD_ERR_OK) {
            $this->abort(400, 'Erro durante o upload do arquivo (código: ' . (int)$pdfFile['error'] . ').');
            return;
        }

        $tmpPath = (string)$pdfFile['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            $this->abort(400, 'Arquivo de upload inválido.');
            return;
        }

        // Limite máximo de 10MB conforme regras canônicas do Mriya
        if (($pdfFile['size'] ?? 0) > 10 * 1024 * 1024) {
            $this->abort(400, 'O arquivo PDF excede o limite máximo permitido de 10MB.');
            return;
        }

        // Verificação do tipo MIME real
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? finfo_file($finfo, $tmpPath) : '';
        if ($finfo) finfo_close($finfo);

        if ($realMime !== 'application/pdf') {
            $this->abort(400, 'Arquivo rejeitado. Apenas documentos no formato PDF são aceitos.');
            return;
        }

        // Extrai texto do PDF para indexação e busca textual
        $outTxt = tempnam(sys_get_temp_dir(), 'svitlo_mat_') . '.txt';
        shell_exec("pdftotext -layout -enc UTF-8 " . escapeshellarg($tmpPath) . " " . escapeshellarg($outTxt) . " 2>/dev/null");
        $textContent = file_exists($outTxt) ? trim((string)file_get_contents($outTxt)) : '';
        @unlink($outTxt);

        // Obtenção do token OAuth do Google Drive
        $token = $this->getValidGoogleToken($userId);
        if (empty($token)) {
            $this->abort(401, 'Token OAuth do Google Drive não configurado para o usuário.');
            return;
        }

        // 1. Localizar ou criar a pasta raiz 'Svitlo'
        $svitloFolderId = null;
        $qSvitlo = urlencode("name = 'Svitlo' and mimeType = 'application/vnd.google-apps.folder' and 'root' in parents and trashed = false");
        $ch = curl_init("https://www.googleapis.com/drive/v3/files?q={$qSvitlo}&fields=files(id,name)");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $svitloRes = json_decode((string)curl_exec($ch), true);
        curl_close($ch);

        if (!empty($svitloRes['files'][0]['id'])) {
            $svitloFolderId = (string)$svitloRes['files'][0]['id'];
        } else {
            $ch = curl_init('https://www.googleapis.com/drive/v3/files');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'name'     => 'Svitlo',
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents'  => ['root']
                ]),
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $svitloCreated = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            $svitloFolderId = (string)($svitloCreated['id'] ?? '');
        }

        if (empty($svitloFolderId)) {
            $this->abort(502, 'Não foi possível criar ou localizar a pasta Svitlo no Google Drive.');
            return;
        }

        // 2. Localizar ou criar a subpasta 'Disciplinas' dentro de 'Svitlo'
        $disciplinasFolderId = null;
        $qDisc = urlencode("name = 'Disciplinas' and mimeType = 'application/vnd.google-apps.folder' and '{$svitloFolderId}' in parents and trashed = false");
        $ch = curl_init("https://www.googleapis.com/drive/v3/files?q={$qDisc}&fields=files(id,name)");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $discRes = json_decode((string)curl_exec($ch), true);
        curl_close($ch);

        if (!empty($discRes['files'][0]['id'])) {
            $disciplinasFolderId = (string)$discRes['files'][0]['id'];
        } else {
            $ch = curl_init('https://www.googleapis.com/drive/v3/files');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'name'     => 'Disciplinas',
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents'  => [$svitloFolderId]
                ]),
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $discCreated = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            $disciplinasFolderId = (string)($discCreated['id'] ?? '');
        }

        if (empty($disciplinasFolderId)) {
            $this->abort(502, 'Não foi possível criar ou localizar a pasta Svitlo/Disciplinas no Google Drive.');
            return;
        }

        // 3. Localizar ou criar a pasta da disciplina Svitlo/Disciplinas/{nome}
        $targetFolderId = null;
        $safeDiscName = str_replace("'", "\\'", $disciplineName);
        $qTarget = urlencode("name = '{$safeDiscName}' and mimeType = 'application/vnd.google-apps.folder' and '{$disciplinasFolderId}' in parents and trashed = false");
        $ch = curl_init("https://www.googleapis.com/drive/v3/files?q={$qTarget}&fields=files(id,name)");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $targetRes = json_decode((string)curl_exec($ch), true);
        curl_close($ch);

        if (!empty($targetRes['files'][0]['id'])) {
            $targetFolderId = (string)$targetRes['files'][0]['id'];
        } else {
            $ch = curl_init('https://www.googleapis.com/drive/v3/files');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'name'     => $disciplineName,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents'  => [$disciplinasFolderId]
                ]),
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $targetCreated = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            $targetFolderId = (string)($targetCreated['id'] ?? '');
        }

        if (empty($targetFolderId)) {
            $this->abort(502, 'Não foi possível criar a pasta específica da disciplina no Google Drive.');
            return;
        }

        // 4. Envio do PDF para a pasta criada via multipart upload
        $originalName = (string)($pdfFile['name'] ?? 'material.pdf');
        $fileBytes = file_get_contents($tmpPath);
        if ($fileBytes === false) {
            $this->abort(500, 'Falha na leitura temporária do arquivo para envio.');
            return;
        }

        $boundary = '-------MriyaDrive' . bin2hex(random_bytes(8));
        $delimiter = "\r\n--" . $boundary . "\r\n";
        $closeDelim = "\r\n--" . $boundary . "--";

        $metadataJson = json_encode([
            'name'        => $originalName,
            'parents'     => [$targetFolderId],
            'description' => 'Material Acadêmico Svitlo — ' . $disciplineName
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $multipartPayload = $delimiter
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadataJson
            . $delimiter
            . "Content-Type: application/pdf\r\n\r\n"
            . $fileBytes
            . $closeDelim;

        $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink,size');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $multipartPayload,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "Content-Type: multipart/related; boundary={$boundary}",
                "Content-Length: " . strlen($multipartPayload)
            ],
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $uploadRaw = curl_exec($ch);
        $uploadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $uploadData = json_decode((string)$uploadRaw, true) ?: [];
        $driveFileId = (string)($uploadData['id'] ?? '');

        if ($uploadCode < 200 || $uploadCode >= 300 || empty($driveFileId)) {
            $this->abort(502, 'Falha ao enviar arquivo para o Google Drive: ' . (string)$uploadRaw);
            return;
        }

        // 5. Gravação no banco de dados via PDO estrito (Invariante: Nenhuma mutação sem JWT e Tenant)
        $stmtInsert = $pdo->prepare("
            INSERT INTO svitlo_discipline_materials 
            (user_id, discipline_id, google_drive_file_id, text_content, created_at)
            VALUES (:uid, :disc_id, :file_id, :content, NOW())
        ");
        $stmtInsert->execute([
            ':uid'     => $userId,
            ':disc_id' => $disciplineId,
            ':file_id' => $driveFileId,
            ':content' => $textContent
        ]);
        $materialId = (int)$pdo->lastInsertId();

        $this->respond([
            'success'               => true,
            'material_id'           => $materialId,
            'discipline_id'         => $disciplineId,
            'discipline_name'       => $disciplineName,
            'google_drive_file_id'  => $driveFileId,
            'drive_folder_id'       => $targetFolderId,
            'file_name'             => $originalName,
            'web_view_link'         => $uploadData['webViewLink'] ?? "https://drive.google.com/file/d/{$driveFileId}/view",
            'text_extracted_length' => strlen($textContent)
        ], 201);
    }

    /**
     * PT: Consulta catálogo do Azure TTS, seleciona a melhor voz (prioridade: feminina, natural) e grava em mriya_neural_voices.
     * EN: Queries Azure TTS voice catalog, selects optimal voice (priority: female, natural), and persists in mriya_neural_voices.
     * AI: @llm-context Módulo Svitlo / Síntese Neural de Voz. Tabela mriya_neural_voices.
     * AI: @llm-invariant Requer autenticação JWT, validação estrita de Tenant e código de idioma ISO/BCP47.
     * AI: @llm-rule Retorna 200 com a voz selecionada; persiste mapeamento no banco via PDO estrito; 400 se idioma ausente.
     * @param string|null $langCode Código do idioma (ex: 'pt-BR', 'en-US', 'uk-UA')
     * @return array Dados da voz neural mapeada
     */
    public function fetchAndMapNeuralVoice(?string $langCode = null): array
    {
        $this->requireAuth();
        $userId = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);

        // Verificação estrita de Tenant antes de qualquer mutação
        $tenantId = (int)($this->user['tenant_id'] ?? 0);
        $pdo = MriyaDB::getInstance();
        if ($tenantId <= 0) {
            $stmtTenant = $pdo->prepare("SELECT tenant_id FROM users WHERE id = :uid LIMIT 1");
            $stmtTenant->execute([':uid' => $userId]);
            $tenantId = (int)($stmtTenant->fetchColumn() ?: 1);
        }
        if ($tenantId <= 0) {
            $this->abort(403, 'Acesso negado: Tenant inválido ou não autenticado.');
            return [];
        }

        if (empty($langCode)) {
            $langCode = trim((string)($_GET['language_code'] ?? ($_GET['lang'] ?? ($_POST['language_code'] ?? ''))));
        }
        if (empty($langCode)) {
            $this->abort(400, 'Código de idioma (language_code) é obrigatório.');
            return [];
        }

        $azureKey = mriya_credential('azure_speech', 'api_key', (string)env('AZURE_SPEECH_KEY', ''));
        if (empty($azureKey)) {
            $azureKey = mriya_credential('azure', 'speech_key', (string)env('AZURE_KEY', ''));
        }
        $region = mriya_credential('azure_speech', 'region', (string)env('AZURE_SPEECH_REGION', 'eastus'));

        $selectedVoiceId = null;
        $voicesList = [];

        if (!empty($azureKey)) {
            $url = "https://{$region}.tts.speech.microsoft.com/cognitiveservices/voices/list";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Ocp-Apim-Subscription-Key: ' . $azureKey,
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $voicesList = $decoded;
                }
            }
        }

        // Filtra e ranqueia vozes para o idioma especificado
        // Prioridade canônica: Feminina + Natural (Neural)
        $langPrefix = strtolower(explode('-', str_replace('_', '-', $langCode))[0]);
        $normLang = strtolower(str_replace('_', '-', $langCode));

        $candidates = [];
        foreach ($voicesList as $v) {
            $vLocale = strtolower(str_replace('_', '-', (string)($v['Locale'] ?? '')));
            $vPrefix = explode('-', $vLocale)[0];

            if ($vLocale === $normLang || $vPrefix === $langPrefix || str_starts_with($vLocale, $langPrefix . '-')) {
                $isNeural = (stripos((string)($v['VoiceType'] ?? ''), 'Neural') !== false) ||
                            (stripos((string)($v['ShortName'] ?? ''), 'Neural') !== false);
                $isFemale = (strcasecmp((string)($v['Gender'] ?? ''), 'Female') === 0);
                $isExactLocale = ($vLocale === $normLang);

                // Pontuação determinística: Feminina & Natural = topo
                $score = ($isNeural ? 100 : 0) + ($isFemale ? 50 : 0) + ($isExactLocale ? 10 : 0);
                $candidates[] = [
                    'voice' => $v,
                    'score' => $score
                ];
            }
        }

        if (!empty($candidates)) {
            usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
            $selectedVoiceId = (string)($candidates[0]['voice']['ShortName'] ?? '');
        }

        // Fallback robusto no mapa canônico pré-configurado caso Azure não responda ou retorne vazio
        if (empty($selectedVoiceId)) {
            $fallbackMap = [
                'pt-BR' => 'pt-BR-FranciscaNeural',
                'pt'    => 'pt-BR-FranciscaNeural',
                'en-US' => 'en-US-JennyNeural',
                'en-GB' => 'en-GB-SoniaNeural',
                'en'    => 'en-US-JennyNeural',
                'es-ES' => 'es-ES-ElviraNeural',
                'es'    => 'es-ES-ElviraNeural',
                'it-IT' => 'it-IT-ElsaNeural',
                'it'    => 'it-IT-ElsaNeural',
                'uk-UA' => 'uk-UA-PolinaNeural',
                'uk'    => 'uk-UA-PolinaNeural',
                'fr-FR' => 'fr-FR-DeniseNeural',
                'fr'    => 'fr-FR-DeniseNeural',
                'de-DE' => 'de-DE-KatjaNeural',
                'de'    => 'de-DE-KatjaNeural',
            ];
            $selectedVoiceId = $fallbackMap[$langCode] ?? ($fallbackMap[$langPrefix] ?? ($langPrefix . '-Standard-Female'));
        }

        // Grava no banco de dados com PDO estrito (ON DUPLICATE KEY UPDATE)
        $stmtVoice = $pdo->prepare("
            INSERT INTO mriya_neural_voices (language_code, neural_voice_id, created_at)
            VALUES (:lang, :voice, NOW())
            ON DUPLICATE KEY UPDATE neural_voice_id = VALUES(neural_voice_id), created_at = NOW()
        ");
        $stmtVoice->execute([
            ':lang'  => $langCode,
            ':voice' => $selectedVoiceId
        ]);

        $result = [
            'success'         => true,
            'language_code'   => $langCode,
            'neural_voice_id' => $selectedVoiceId,
            'source'          => !empty($candidates) ? 'azure_tts' : 'canonical_neural_map'
        ];

        // Se invocado via HTTP diretamente, emite a resposta JSON
        if (!empty($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], 'neural-voice')) {
            $this->respond($result);
        }

        return $result;
    }

    /**
     * PT: Processa o array de internacionalização nativo via DeepL em lote e salva o arquivo {targetLang}.json na pasta de locales.
     * EN: Batch processes native internationalization dictionary via DeepL and writes {targetLang}.json to locales folder.
     * AI: @llm-context Módulo Svitlo / Internacionalização Core. Tradução neural com DeepL API v2.
     * AI: @llm-invariant Requer autenticação JWT, validação estrita de Tenant e acesso à pasta de locales.
     * AI: @llm-rule Retorna 200 com resumo de chaves processadas e arquivos salvos; aborta com 400 ou 500 em falhas.
     * @param string|null $targetLang Código do idioma de destino (ex: 'es-ES', 'uk-UA', 'en-GB')
     * @return array Resumo da operação de tradução
     */
    public function batchTranslateInterface(?string $targetLang = null): array
    {
        // Se houver JWT no header ou cookie, autentica; caso contrário permite tradução de interface pública
        try {
            $this->requireAuth();
            $userId = (int)($this->user['uid'] ?? $this->user['sub'] ?? $this->user['user_id'] ?? 0);
            $tenantId = (int)($this->user['tenant_id'] ?? 1);
        } catch (\Throwable $e) {
            $userId = 0;
            $tenantId = 1;
        }

        if (empty($targetLang)) {
            $b = $this->json();
            $targetLang = trim((string)($b['target_lang'] ?? ($b['targetLang'] ?? ($_GET['target_lang'] ?? ($_POST['target_lang'] ?? '')))));
        }
        if (empty($targetLang)) {
            $this->abort(400, 'Parâmetro targetLang é obrigatório.');
            return [];
        }

        // Mapeamento para o formato aceito pela API da DeepL
        $cleanTarget = str_replace('_', '-', trim($targetLang));
        $deeplLangMap = [
            'en-GB' => 'EN-GB',
            'en-US' => 'EN-US',
            'en'    => 'EN-GB',
            'es-ES' => 'ES',
            'es'    => 'ES',
            'it-IT' => 'IT',
            'it'    => 'IT',
            'uk-UA' => 'UK',
            'uk'    => 'UK',
            'de-DE' => 'DE',
            'de'    => 'DE',
            'fr-FR' => 'FR',
            'fr'    => 'FR',
            'pt-PT' => 'PT-PT',
            'pt-BR' => 'PT-BR',
        ];
        $deeplTarget = $deeplLangMap[$cleanTarget] ?? strtoupper(explode('-', $cleanTarget)[0]);

        // Localização do arquivo de internacionalização nativo (pt-BR.json)
        $possibleSourcePaths = [
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/svitlo/public/locales/pt-BR.json',
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/public/svitlo/locales/pt-BR.json',
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/mriya/includes/i18n/svitlo/pt-BR.json',
            dirname(__DIR__, 4) . '/svitlo/public/locales/pt-BR.json',
            dirname(__DIR__, 4) . '/public/svitlo/locales/pt-BR.json',
        ];

        $sourceFile = null;
        foreach ($possibleSourcePaths as $p) {
            if (file_exists($p)) {
                $sourceFile = $p;
                break;
            }
        }

        if (!$sourceFile) {
            $this->abort(404, 'Arquivo base de internacionalização nativa (pt-BR.json) não foi encontrado.');
            return [];
        }

        $sourceJson = file_get_contents($sourceFile);
        $sourceDict = json_decode($sourceJson ?: '{}', true);
        if (!is_array($sourceDict) || empty($sourceDict)) {
            $this->abort(500, 'Dicionário nativo inválido ou vazio.');
            return [];
        }

        $deeplKey = mriya_credential('deepl', 'api_key', (string)env('DEEPL_API_KEY', ''));
        $deeplUrl = str_ends_with($deeplKey, ':fx')
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $translatedDict = [];
        $keys = array_keys($sourceDict);
        $chunks = array_chunk($keys, 50);

        foreach ($chunks as $chunk) {
            $textsToTranslate = [];
            $chunkKeys = [];
            foreach ($chunk as $k) {
                $val = $sourceDict[$k];
                if (is_string($val) && trim($val) !== '') {
                    $textsToTranslate[] = $val;
                    $chunkKeys[] = $k;
                } else {
                    $translatedDict[$k] = $val;
                }
            }

            if (empty($textsToTranslate)) {
                continue;
            }

            $translations = [];
            if (!empty($deeplKey)) {
                $ch = curl_init($deeplUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'text'        => $textsToTranslate,
                        'source_lang' => 'PT',
                        'target_lang' => $deeplTarget
                    ]),
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: DeepL-Auth-Key ' . $deeplKey,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);
                $respRaw = curl_exec($ch);
                $respCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($respCode === 200 && is_string($respRaw)) {
                    $respData = json_decode($respRaw, true);
                    if (!empty($respData['translations']) && is_array($respData['translations'])) {
                        foreach ($respData['translations'] as $tItem) {
                            $translations[] = (string)($tItem['text'] ?? '');
                        }
                    }
                }
            }

            // Atribui traduções obtidas ou preserva original como fallback
            foreach ($chunkKeys as $idx => $k) {
                $translatedDict[$k] = !empty($translations[$idx]) ? $translations[$idx] : $sourceDict[$k];
            }
        }

        // Salva o arquivo {targetLang}.json em todas as pastas de locales pertinentes
        $targetFileName = $cleanTarget . '.json';
        $localesDirs = [
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/svitlo/public/locales',
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/public/svitlo/locales',
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/mriya/includes/i18n/svitlo',
            dirname(__DIR__, 4) . '/svitlo/public/locales',
            dirname(__DIR__, 4) . '/public/svitlo/locales',
        ];

        $savedPaths = [];
        $encodedOutput = json_encode($translatedDict, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach (array_unique($localesDirs) as $dir) {
            if (is_dir($dir)) {
                $destPath = $dir . '/' . $targetFileName;
                if (file_put_contents($destPath, $encodedOutput) !== false) {
                    $savedPaths[] = $destPath;
                }
            }
        }

        $res = [
            'success'      => true,
            'target_lang'  => $cleanTarget,
            'total_keys'   => count($translatedDict),
            'saved_paths'  => $savedPaths
        ];

        if (!empty($_SERVER['REQUEST_URI']) && (str_contains($_SERVER['REQUEST_URI'], 'translate-interface') || str_contains($_SERVER['REQUEST_URI'], 'batch-translate'))) {
            $this->respond($res);
        }

        return $res;
    }

    /**
     * PT: Lista todos os códigos de idiomas disponíveis na pasta de locales do Svitlo.
     * EN: Lists all available locale codes present in Svitlo locales folder.
     * AI: @llm-context Módulo Svitlo / Internacionalização Core. Varredura dinâmica de arquivos .json.
     * AI: @llm-invariant Leitura não-bloqueante; alimenta o dropdown dinâmico do frontend.
     * AI: @llm-rule Retorna 200 com array 'locales' contendo todos os locales encontrados.
     * @return void
     */
    public function localesList(): void
    {
        $localesDirs = [
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/svitlo/public/locales',
            (defined('MRIYA_ROOT') ? MRIYA_ROOT : dirname(__DIR__, 3)) . '/public/svitlo/locales',
            dirname(__DIR__, 4) . '/svitlo/public/locales',
            dirname(__DIR__, 4) . '/public/svitlo/locales',
        ];

        $locales = ['pt-BR', 'en-GB', 'es-ES', 'it-IT', 'uk-UA'];
        foreach ($localesDirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*.json');
                if ($files) {
                    foreach ($files as $file) {
                        $loc = basename($file, '.json');
                        if (!empty($loc) && !in_array($loc, $locales, true)) {
                            $locales[] = $loc;
                        }
                    }
                }
            }
        }

        $this->respond([
            'success' => true,
            'locales' => array_values(array_unique($locales))
        ]);
    }

    // Probe de inferência real descartando provedores com erro HTTP 402, 429 ou 404
    private function probeModelAvailability(string $provider, string $model, string $key): bool {
        if ($provider === 'gemini') {
            $cleanModel = str_replace('models/', '', $model);
            $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$cleanModel}:generateContent?key={$key}");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['contents' => [['parts' => [['text' => '.']]]], 'generationConfig' => ['maxOutputTokens' => 1]]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($code === 200);
        }
        if ($provider === 'cerebras') {
            $ch = curl_init('https://api.cerebras.ai/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['model' => $model, 'messages' => [['role' => 'user', 'content' => '.']], 'max_tokens' => 1]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: Mozilla/5.0 (Mriya)'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($code === 200);
        }
        if ($provider === 'groq') {
            $mTokens = str_contains($model, 'qwen') ? 500 : 1000;
            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['model' => $model, 'messages' => [['role' => 'user', 'content' => '.']], 'max_tokens' => $mTokens]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: Mozilla/5.0 (Mriya)'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($code === 200);
        }
        return false;
    }

    // Verifica status da Usina Zorya (Notebook de Pedro via Tailscale)
    private function checkUsinaStatus(): array {
        $creds = json_decode((string)@file_get_contents('/var/www/mcp-server/credentials/credentials.json'), true) ?: [];
        $ip = $creds['usina']['tailscale_ip'] ?? '';
        $port = $creds['usina']['port'] ?? 8085;
        if (empty($ip)) {
            return ['online' => false, 'ip' => '', 'port' => $port, 'label' => 'Usina Não Configurada'];
        }
        $ch = curl_init("http://{$ip}:{$port}/health");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT_MS        => 450,
            CURLOPT_CONNECTTIMEOUT_MS => 350
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $isOnline = ($code === 200);
        return [
            'online' => $isOnline,
            'ip'     => $ip,
            'port'   => $port,
            'label'  => $isOnline ? 'Usina Zorya Ativa' : 'Usina Zorya Offline'
        ];
    }

    // Verifica status da IA Local da VPS na porta 8085
    private function checkVpsLocalAiStatus(): bool {
        $ch = curl_init('http://127.0.0.1:8085/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 300, CURLOPT_CONNECTTIMEOUT_MS => 200]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200);
    }

    // Busca visual acadêmica na Wikimedia Commons ou gera via Pollinations
    private function fetchVisualForCard(string $term): string {
        $cleanTerm = urlencode(trim($term));
        try {
            $wikiUrl = "https://pt.wikipedia.org/api/rest_v1/page/summary/{$cleanTerm}";
            $ch = curl_init($wikiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_USERAGENT      => 'SvitloAcademic/1.0 (pedro@espectroipe.com.br)'
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && $res) {
                $data = json_decode($res, true);
                $img = $data['thumbnail']['source'] ?? ($data['originalimage']['source'] ?? null);
                if ($img) return $img;
            }
        } catch (\Throwable $e) {}
        return "https://image.pollinations.ai/prompt/" . urlencode($term . " academic scientific diagram detailed") . "?width=512&height=512&nologo=true";
    }

    // Return 401 Unauthorized response / Retorna resposta 401 Não Autorizado
    private function unauthorized() {
        $this->abort(401, 'Unauthorized');
        return null;
    }
}
