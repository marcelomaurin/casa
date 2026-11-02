import importlib.util
import os
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault('CASA_TAREFAS_CHAVE', 'fixture')
os.environ.setdefault('CASA_TAREFAS_AGENTE_WORKSPACE', tempfile.gettempdir())
import google_home_agent as agent
from queue_bridge import QueueBridge
from fastapi.testclient import TestClient

class GoogleHomeTests(unittest.TestCase):
    def setUp(self):
        self.cast = Mock(uuid='uuid-sala', name='Sala', cast_info=SimpleNamespace(host='192.168.2.50'))
        self.cast.name = 'Sala'
        self.cast.media_controller.status.player_state = 'PLAYING'
        agent._casts = {'uuid-sala': self.cast}
        agent._browser = Mock()
        self.client = TestClient(agent.app, client=('127.0.0.1', 1234))

    def test_unknown_or_ambiguous_target_never_uses_first_device(self):
        with self.assertRaisesRegex(RuntimeError, 'nao encontrado'):
            agent.choose('Quarto')
        agent._casts['uuid-cozinha'] = Mock()
        agent._casts['uuid-cozinha'].name = 'Cozinha'
        with self.assertRaisesRegex(RuntimeError, 'Selecione'):
            agent.choose()
        self.assertIs(agent.choose('Sala'), self.cast)

    def test_remote_controls_require_auth_but_speaker_can_download_wav(self):
        with patch.object(agent, 'API_TOKEN', 'test-key'):
            self.assertEqual(self.client.post('/speak', json={'texto':'ola'}).status_code, 401)
            with patch.object(agent, 'say', return_value={'status':'ok'}) as speak:
                r = self.client.post('/speak', json={'texto':'ola'}, headers={'X-API-Key':'test-key'})
                self.assertEqual(r.status_code, 200)
                speak.assert_called_once()
        with tempfile.TemporaryDirectory() as temp, patch.object(agent, 'AUDIO_DIR', Path(temp)):
            (Path(temp)/'fala.wav').write_bytes(b'RIFF\0\0\0\0WAVEdata')
            self.assertEqual(self.client.get('/audio/fala.wav').status_code, 200)
            (Path(temp)/'broken.wav').write_bytes(b'not audio')
            self.assertEqual(self.client.get('/audio/broken.wav').status_code, 404)
            self.assertEqual(self.client.get('/audio/file.php').status_code, 404)

    def test_computer_uses_v1_context_and_errors_are_not_spoken(self):
        response = Mock()
        response.json.return_value = {'status':'sucesso', 'resposta':'resposta'}
        with patch.object(agent.requests, 'post', return_value=response) as post:
            self.assertEqual(agent.computer('status', {'id_plano':3}), 'resposta')
            self.assertIn('/api/v1/comando', post.call_args.args[0])
            self.assertEqual(post.call_args.kwargs['json']['task_context'], {'id_plano':3})
            response.json.return_value = {'status':'erro', 'mensagem':'falhou'}
            with self.assertRaisesRegex(RuntimeError, 'falhou'):
                agent.computer('status')

    def test_tts_does_not_play_locally_and_requires_existing_audio(self):
        response = Mock()
        response.json.return_value = {'audio_url':'/audio/saida.wav'}
        with patch.object(agent.requests, 'post', return_value=response) as post, patch.object(agent, 'audio_path') as check:
            self.assertEqual(agent.synthesize('teste'), 'saida.wav')
            self.assertFalse(post.call_args.kwargs['json']['reproduzir'])
            check.assert_called_once_with('saida.wav')

    def test_cast_must_confirm_playback(self):
        wav = Mock()
        wav.__enter__ = Mock(return_value=SimpleNamespace(getnframes=lambda:0,getframerate=lambda:16000))
        wav.__exit__ = Mock(return_value=False)
        url = 'http://192.168.2.13:8100/audio/fala.wav'
        self.cast.media_controller.status.content_id = url
        updates = [0]
        def update():
            updates[0] += 1
            if updates[0] > 1:
                self.cast.media_controller.status.player_state = 'IDLE'
        self.cast.media_controller.update_status.side_effect = update
        with patch.object(agent, 'synthesize', return_value='fala.wav'), patch.object(agent, 'PUBLIC_BASE', 'http://192.168.2.13:8100'), patch.object(agent, 'audio_path', return_value=Path('fake.wav')), patch.object(agent.wave, 'open', return_value=wav), patch.object(agent,'PLAYBACK_TIMEOUT',0.01):
            self.assertEqual(agent.say('teste')['reproducao'], 'iniciada')
            self.cast.media_controller.update_status.side_effect = None
            self.cast.media_controller.status.player_state = 'PLAYING'
            self.cast.media_controller.status.content_id = 'http://old/audio.wav'
            with self.assertRaisesRegex(RuntimeError, 'nao confirmou'):
                agent.say('teste')

    def test_queue_reuses_reservations_and_acknowledges_only_real_result(self):
        speak = Mock(return_value={'reproducao':'iniciada'})
        bridge = QueueBridge(speak, lambda: {})
        context = Mock()
        result = bridge.execute({'payload':{'texto':'teste','device':'Sala'}}, context)
        self.assertEqual(result['reproducao'], 'iniciada')
        context.renovar.assert_called_once()
        speak.assert_called_once_with('teste', 'Sala', 'padrao')
        with self.assertRaises(bridge.worker.FalhaDefinitiva):
            bridge.execute({'payload':{'comando':'nao executar acao duas vezes'}}, context)
        # Exercitar o motor real: erro de Cast vira falha repetivel, nao conclusao.
        bridge.daemon.fila = Mock()
        speak.side_effect = RuntimeError('Google Home offline')
        bridge.daemon.executar({'id':42,'executor':'google_home','payload':{'texto':'teste'}})
        bridge.daemon.fila.falhar.assert_called_once()
        bridge.daemon.fila.concluir.assert_not_called()
        bridge.daemon.pool.shutdown()

    def test_queue_is_serial_and_does_not_take_other_executors(self):
        bridge = QueueBridge(Mock(), lambda: {})
        self.assertEqual(bridge.cfg.executores, ['google_home'])
        self.assertEqual(bridge.cfg.paralelo, 1)
        self.assertEqual(bridge.cfg.mordomo_intervalo, 0)
        bridge.daemon.pool.shutdown()

if __name__ == '__main__':
    unittest.main(verbosity=2)
