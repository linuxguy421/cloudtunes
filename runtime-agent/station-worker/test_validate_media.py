"""Unit tests for the station-worker media preflight validator."""

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from validate_media import MediaValidationError, inspect_ogg_vorbis


class InspectOggVorbisTests(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.media = Path(self.temp_dir.name) / "track.ogg"
        self.media.write_bytes(b"test fixture")

    def tearDown(self):
        self.temp_dir.cleanup()

    @staticmethod
    def probe_result(
        *,
        format_name="ogg",
        codec="vorbis",
        sample_rate="44100",
        channels=2,
        returncode=0,
        stderr="",
    ):
        return type(
            "CompletedProcess",
            (),
            {
                "returncode": returncode,
                "stdout": json.dumps(
                    {
                        "format": {"format_name": format_name},
                        "streams": [
                            {
                                "codec_type": "audio",
                                "codec_name": codec,
                                "sample_rate": sample_rate,
                                "channels": channels,
                            }
                        ],
                    }
                ),
                "stderr": stderr,
            },
        )()

    @patch("validate_media.subprocess.run")
    def test_accepts_stereo_ogg_vorbis(self, run):
        run.return_value = self.probe_result(sample_rate="48000")
        metadata = inspect_ogg_vorbis(self.media)
        self.assertEqual(
            metadata,
            {
                "container": "ogg",
                "codec": "vorbis",
                "sample_rate": 48000,
                "channels": 2,
            },
        )
        self.assertEqual(run.call_args.args[0][0], "ffprobe")

    def test_rejects_non_ogg_extension_before_probing(self):
        mp3 = self.media.with_suffix(".mp3")
        mp3.write_bytes(b"not really audio")
        with patch("validate_media.subprocess.run") as run:
            with self.assertRaisesRegex(MediaValidationError, r"\.ogg"):
                inspect_ogg_vorbis(mp3)
            run.assert_not_called()

    @patch("validate_media.subprocess.run")
    def test_rejects_non_ogg_container(self, run):
        run.return_value = self.probe_result(format_name="mp3")
        with self.assertRaisesRegex(MediaValidationError, "container must be Ogg"):
            inspect_ogg_vorbis(self.media)

    @patch("validate_media.subprocess.run")
    def test_rejects_opus_in_ogg(self, run):
        run.return_value = self.probe_result(codec="opus")
        with self.assertRaisesRegex(MediaValidationError, "codec must be Vorbis"):
            inspect_ogg_vorbis(self.media)

    @patch("validate_media.subprocess.run")
    def test_rejects_mono_vorbis(self, run):
        run.return_value = self.probe_result(channels=1)
        with self.assertRaisesRegex(MediaValidationError, "must be stereo"):
            inspect_ogg_vorbis(self.media)

    @patch("validate_media.subprocess.run")
    def test_rejects_multiple_audio_streams(self, run):
        result = self.probe_result()
        result.stdout = json.dumps(
            {
                "format": {"format_name": "ogg"},
                "streams": [
                    {"codec_name": "vorbis", "sample_rate": "44100", "channels": 2},
                    {"codec_name": "vorbis", "sample_rate": "44100", "channels": 2},
                ],
            }
        )
        run.return_value = result
        with self.assertRaisesRegex(MediaValidationError, "exactly one audio stream"):
            inspect_ogg_vorbis(self.media)

    @patch("validate_media.subprocess.run")
    def test_reports_ffprobe_failure(self, run):
        run.return_value = self.probe_result(
            returncode=1,
            stderr="Invalid data found when processing input",
        )
        with self.assertRaisesRegex(MediaValidationError, "Invalid data found"):
            inspect_ogg_vorbis(self.media)


if __name__ == "__main__":
    unittest.main()
