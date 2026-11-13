import os
import re
import subprocess
import sys

paths = [
    r'C:\mriya\mriya\api\v1\controllers\SvitloController.php',
    '/var/www/mriya/api/v1/controllers/SvitloController.php',
    '/var/www/mriya/mriya/api/v1/controllers/SvitloController.php'
]

for path in paths:
    if not os.path.exists(path):
        continue
    with open(path, 'rb') as f:
        raw = f.read().decode('utf-8')
    newline = '\r\n' if '\r\n' in raw else '\n'
    content = raw.replace('\r\n', '\n')

    # 1. Patch line ~13220
    old_target1 = """        // PT: Coleta trechos de letras reais para análise
        $trackSummaries = [];
        foreach (array_slice($tracks, 0, 20) as $t) {
            $lyrics = $this->fetchLyrics($t['artist'], $t['name']);
            $trackSummaries[] = [
                'name'           => $t['name'],
                'artist'         => $t['artist'],
                'play_count'     => $t['playcount'],
                'lyrics_preview' => mb_substr($lyrics, 0, 150),
            ];
        }"""

    new_target1 = """        // PT: Coleta trechos de letras reais para análise em lotes de 10 com pausa de 1s (Last.FM rate-limit)
        $trackSummaries = [];
        foreach (array_chunk(array_slice($tracks, 0, 20), 10) as $trackBatch) {
            foreach ($trackBatch as $t) {
                $lyrics = $this->fetchLyrics($t['artist'], $t['name']);
                $trackSummaries[] = [
                    'name'           => $t['name'],
                    'artist'         => $t['artist'],
                    'play_count'     => $t['playcount'],
                    'lyrics_preview' => mb_substr($lyrics, 0, 150),
                ];
            }
            sleep(1);
        }"""

    if old_target1 in content:
        content = content.replace(old_target1, new_target1)
        print(f"Patched target 1 in {path}")
    else:
        print(f"Target 1 already patched or not found in {path}")

    # 2. Patch line ~3250
    old_target2 = """        $tracksToProcess = array_slice($tracks, 0, 10);
        $allCards = [];
        $cardsByDeck = [];
        $deckSummaries = [];
        $errors = [];

        foreach ($tracksToProcess as $t) {"""

    new_target2 = """        $tracksToProcess = array_slice($tracks, 0, 10);
        $allCards = [];
        $cardsByDeck = [];
        $deckSummaries = [];
        $errors = [];

        // Processa faixas em lotes de 10 com pausa de 1s contra rate-limit
        foreach (array_chunk($tracksToProcess, 10) as $trackBatch) {
            foreach ($trackBatch as $t) {"""

    # Also need the closing bracket and sleep(1) before Auto-envio para Anki
    old_loop_end = """            } catch (\\Throwable $e) {
                $err = "Erro gerando cards para {$artist} - {$trackName}: " . $e->getMessage();
                $this->logError('musicGenerate', $err);
                $errors[] = $err;
            }
        }

        // Auto-envio para Anki"""

    new_loop_end = """            } catch (\\Throwable $e) {
                $err = "Erro gerando cards para {$artist} - {$trackName}: " . $e->getMessage();
                $this->logError('musicGenerate', $err);
                $errors[] = $err;
            }
        }
        sleep(1);
    }

        // Auto-envio para Anki"""

    if old_target2 in content and old_loop_end in content:
        content = content.replace(old_target2, new_target2).replace(old_loop_end, new_loop_end)
        print(f"Patched target 2 in {path}")
    else:
        print(f"Target 2 already patched or not found in {path}")

    final_text = content.replace('\n', newline)
    with open(path, 'wb') as f:
        f.write(final_text.encode('utf-8'))

    res = subprocess.run(['php', '-l', path], capture_output=True, text=True)
    print(f"PHP syntax check {path}: {res.stdout.strip() or res.stderr.strip()}")
