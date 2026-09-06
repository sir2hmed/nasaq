"""Local slide rendering and safe FFmpeg/FFprobe process boundaries."""

import json
import subprocess
import textwrap
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from PIL import Image, ImageDraw, ImageFont


class VideoServiceError(Exception):
    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code
        self.safe_message = message


@dataclass(frozen=True, slots=True)
class RenderedVideo:
    path: Path
    duration_seconds: float
    width: int
    height: int
    codec: str
    has_audio: bool


class VideoService:
    width = 1280
    height = 720
    frame_rate = 24

    def __init__(self, ffmpeg_binary: str = "ffmpeg", ffprobe_binary: str = "ffprobe") -> None:
        self.ffmpeg_binary = ffmpeg_binary
        self.ffprobe_binary = ffprobe_binary

    def render(
        self,
        output_directory: Path,
        scenes: list[dict[str, Any]],
        scene_duration: float,
        *,
        language: str,
        demo_mode: bool,
        audio_path: Path | None = None,
    ) -> RenderedVideo:
        output_directory.mkdir(parents=True, exist_ok=True)
        resolved_scene_duration = scene_duration
        if audio_path is not None:
            audio_duration = self._probe_duration(audio_path, "a:0")
            resolved_scene_duration = max(
                scene_duration,
                (audio_duration / len(scenes)) + 0.35,
            )

        frame_paths = [
            self._create_slide(
                output_directory,
                scene,
                index,
                len(scenes),
                language,
                demo_mode,
            )
            for index, scene in enumerate(scenes, start=1)
        ]
        manifest_path = output_directory / "scenes.ffconcat"
        manifest_lines: list[str] = ["ffconcat version 1.0"]
        for frame_path in frame_paths:
            manifest_lines.extend(
                [f"file '{frame_path.name}'", f"duration {resolved_scene_duration:.3f}"]
            )
        manifest_lines.append(f"file '{frame_paths[-1].name}'")
        manifest_path.write_text("\n".join(manifest_lines) + "\n", encoding="utf-8")

        output_path = output_directory / "nasaq-video.mp4"
        silent_path = output_path if audio_path is None else output_directory / "silent-video.mp4"
        total_duration = resolved_scene_duration * len(scenes)
        self._run(
            [
                self.ffmpeg_binary,
                "-hide_banner",
                "-loglevel",
                "error",
                "-y",
                "-f",
                "concat",
                "-safe",
                "1",
                "-i",
                manifest_path.name,
                "-vf",
                f"fps={self.frame_rate},format=yuv420p",
                "-c:v",
                "libx264",
                "-preset",
                "veryfast",
                "-movflags",
                "+faststart",
                "-t",
                f"{total_duration:.3f}",
                silent_path.name,
            ],
            output_directory,
        )
        if audio_path is not None:
            self._run(
                [
                    self.ffmpeg_binary,
                    "-hide_banner",
                    "-loglevel",
                    "error",
                    "-y",
                    "-i",
                    silent_path.name,
                    "-i",
                    audio_path.name,
                    "-map",
                    "0:v:0",
                    "-map",
                    "1:a:0",
                    "-c:v",
                    "copy",
                    "-c:a",
                    "aac",
                    "-b:a",
                    "128k",
                    "-shortest",
                    "-movflags",
                    "+faststart",
                    output_path.name,
                ],
                output_directory,
            )
            silent_path.unlink(missing_ok=True)

        metadata = self._probe_video(output_path)
        return RenderedVideo(
            path=output_path,
            duration_seconds=metadata["duration"],
            width=metadata["width"],
            height=metadata["height"],
            codec=metadata["codec"],
            has_audio=metadata["has_audio"],
        )

    def _create_slide(
        self,
        output_directory: Path,
        scene: dict[str, Any],
        index: int,
        scene_count: int,
        language: str,
        demo_mode: bool,
    ) -> Path:
        image = Image.new("RGB", (self.width, self.height), "#071514")
        draw = ImageDraw.Draw(image)
        for y in range(self.height):
            ratio = y / self.height
            color = (
                round(7 + 9 * ratio),
                round(21 + 22 * ratio),
                round(20 + 17 * ratio),
            )
            draw.line((0, y, self.width, y), fill=color)
        draw.ellipse((900, -220, 1450, 330), fill="#163f39")
        draw.rounded_rectangle(
            (72, 66, 1208, 654),
            radius=30,
            fill="#0d2321",
            outline="#3d6c63",
            width=2,
        )

        title_font = self._font(34)
        body_font = self._font(48)
        small_font = self._font(24)
        accent = "#dfb762"
        draw.text((112, 102), "NASAQ AI", fill=accent, font=title_font)
        mode = "DEMO MODE" if demo_mode else "REAL MODE"
        mode_bbox = draw.textbbox((0, 0), mode, font=small_font)
        mode_width = mode_bbox[2] - mode_bbox[0]
        draw.text((1168 - mode_width, 110), mode, fill="#9ac0b8", font=small_font)

        text = str(scene["text"])
        wrapped = "\n".join(textwrap.wrap(text, width=42 if language == "ar" else 50))
        align = "right" if language == "ar" else "left"
        body_bbox = draw.multiline_textbbox(
            (0, 0), wrapped, font=body_font, spacing=16, align=align
        )
        body_width = body_bbox[2] - body_bbox[0]
        body_height = body_bbox[3] - body_bbox[1]
        body_x = (self.width - body_width) / 2
        body_y = max(205, (self.height - body_height) / 2)
        draw.multiline_text(
            (body_x, body_y),
            wrapped,
            fill="#f5f1e7",
            font=body_font,
            spacing=16,
            align=align,
        )

        progress_width = 1000
        progress_x = 112
        progress_y = 606
        draw.rounded_rectangle(
            (progress_x, progress_y, progress_x + progress_width, progress_y + 8),
            radius=4,
            fill="#27413d",
        )
        draw.rounded_rectangle(
            (
                progress_x,
                progress_y,
                progress_x + round(progress_width * index / scene_count),
                progress_y + 8,
            ),
            radius=4,
            fill=accent,
        )
        counter = f"{index:02d} / {scene_count:02d}"
        counter_bbox = draw.textbbox((0, 0), counter, font=small_font)
        draw.text(
            (1168 - (counter_bbox[2] - counter_bbox[0]), 580),
            counter,
            fill="#9ac0b8",
            font=small_font,
        )

        path = output_directory / f"scene-{index:02d}.png"
        image.save(path, format="PNG", optimize=True)
        return path

    @staticmethod
    def _font(size: int) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
        for candidate in (
            Path("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"),
            Path("C:/Windows/Fonts/arial.ttf"),
        ):
            if candidate.is_file():
                return ImageFont.truetype(str(candidate), size=size)
        return ImageFont.load_default(size=size)

    def _probe_duration(self, path: Path, stream: str) -> float:
        output = self._run(
            [
                self.ffprobe_binary,
                "-v",
                "error",
                "-select_streams",
                stream,
                "-show_entries",
                "format=duration",
                "-of",
                "json",
                path.name,
            ],
            path.parent,
        )
        try:
            duration = float(json.loads(output)["format"]["duration"])
        except (KeyError, TypeError, ValueError, json.JSONDecodeError) as exc:
            raise VideoServiceError(
                "invalid_audio",
                "The configured speech provider returned audio that FFmpeg could not read.",
            ) from exc
        if duration <= 0:
            raise VideoServiceError("invalid_audio", "Narration audio has no playable duration.")
        return duration

    def _probe_video(self, path: Path) -> dict[str, Any]:
        output = self._run(
            [
                self.ffprobe_binary,
                "-v",
                "error",
                "-show_entries",
                "stream=codec_type,codec_name,width,height:format=duration,format_name",
                "-of",
                "json",
                path.name,
            ],
            path.parent,
        )
        try:
            payload = json.loads(output)
            video_stream = next(
                stream for stream in payload["streams"] if stream["codec_type"] == "video"
            )
            duration = float(payload["format"]["duration"])
            format_names = str(payload["format"]["format_name"]).split(",")
            if "mp4" not in format_names or duration <= 0:
                raise ValueError("not a playable MP4")
            return {
                "duration": round(duration, 3),
                "width": int(video_stream["width"]),
                "height": int(video_stream["height"]),
                "codec": str(video_stream["codec_name"]),
                "has_audio": any(
                    stream.get("codec_type") == "audio" for stream in payload["streams"]
                ),
            }
        except (KeyError, StopIteration, TypeError, ValueError, json.JSONDecodeError) as exc:
            raise VideoServiceError(
                "ffmpeg_invalid_output",
                "FFmpeg completed but did not produce a valid playable MP4.",
            ) from exc

    @staticmethod
    def _run(arguments: list[str], working_directory: Path) -> str:
        try:
            completed = subprocess.run(
                arguments,
                cwd=working_directory,
                check=True,
                capture_output=True,
                text=True,
                timeout=180,
            )
        except FileNotFoundError as exc:
            raise VideoServiceError(
                "ffmpeg_unavailable",
                "FFmpeg is not installed in the video worker environment.",
            ) from exc
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
            raise VideoServiceError(
                "ffmpeg_failed",
                "FFmpeg could not render the video. Review the node configuration and retry.",
            ) from exc
        return completed.stdout
