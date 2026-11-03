import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;
import 'package:intl/intl.dart';

import 'location_service.dart';

/// Burns the capture details into the picture itself (a dark band along the bottom): date and time, GPS position and
/// accuracy, the outlet and the agent. The stamp is part of the pixels, so it travels with the photo wherever it goes
/// (web evidence gallery, exports, the client's report) and a reviewer can read it without opening a record.
///
/// It is applied the moment the photo is taken, on the phone's own clock and GPS. The server independently records when
/// the upload happened and refuses a photo that claims to be older than three days, so the stamp is a visible aid and the
/// server record is the one that is checked.
///
/// Only plain ASCII is drawn: the built-in bitmap fonts have no accents or symbols.
List<String> stampLines({required DateTime takenAt, GpsReading? gps, required String outlet, required String agent, String? title}) {
  String clean(String s, int max) {
    final t = s.replaceAll(RegExp(r'[^\x20-\x7E]'), '').trim();
    return t.length > max ? '${t.substring(0, max - 1)}~' : t;
  }

  final when = DateFormat('dd MMM yyyy  HH:mm:ss').format(takenAt);
  final off = takenAt.timeZoneOffset;
  final sign = off.isNegative ? '-' : '+';
  final tz = 'UTC$sign${off.inHours.abs().toString().padLeft(2, '0')}:${(off.inMinutes.abs() % 60).toString().padLeft(2, '0')}';
  final pos = gps == null
      ? 'GPS: not available'
      : 'GPS ${gps.latitude.toStringAsFixed(5)}, ${gps.longitude.toStringAsFixed(5)}${gps.accuracyM == null ? '' : '  (+/-${gps.accuracyM!.round()} m)'}';
  return [
    if (title != null) title,
    '$when  $tz',
    pos,
    '${clean(outlet, 28)} | ${clean(agent, 18)}',
  ];
}

/// Rewrites the file at [path] as a JPEG with the stamp drawn on it. Runs off the UI thread.
Future<void> stampPhoto(String path, List<String> lines) => compute(_stampSync, _Job(path, lines));

class _Job {
  const _Job(this.path, this.lines);
  final String path;
  final List<String> lines;
}

void _stampSync(_Job job) {
  final file = File(job.path);
  var image = img.decodeImage(file.readAsBytesSync());
  if (image == null) throw StateError('The photo could not be read.');
  image = img.bakeOrientation(image);

  final font = image.width >= 900 ? img.arial24 : img.arial14;
  final lineHeight = font.lineHeight + 6;
  const pad = 10;
  final band = job.lines.length * lineHeight + pad * 2;
  img.fillRect(image, x1: 0, y1: image.height - band, x2: image.width, y2: image.height, color: img.ColorRgba8(0, 0, 0, 165));
  for (var i = 0; i < job.lines.length; i++) {
    img.drawString(image, job.lines[i], font: font, x: pad, y: image.height - band + pad + i * lineHeight, color: img.ColorRgba8(255, 255, 255, 255));
  }
  file.writeAsBytesSync(img.encodeJpg(image, quality: 88));
}
