import 'dart:io';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../../app/theme.dart';
import '../../core/config.dart';
import '../../core/location_service.dart';
import '../../core/photo_stamp.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

/// Photos are compressed by the camera plugin, copied to the app's own storage (the camera cache can be cleared by
/// Android) and queued for upload. They stay on the phone until the server confirms the upload.
Future<File> _persist(String sourcePath, {String ext = '.jpg'}) async {
  final dir = await getApplicationDocumentsDirectory();
  final folder = Directory(p.join(dir.path, 'evidence'));
  if (!await folder.exists()) await folder.create(recursive: true);
  final target = File(p.join(folder.path, '${newId()}$ext'));
  return File(sourcePath).copy(target.path);
}

/// A photo that was just taken, stamped and copied to the app's own storage, ready to be attached to a visit.
class CapturedPhoto {
  const CapturedPhoto(this.file, this.gps);
  final File file;
  final GpsReading? gps;
}

/// Opens the camera (never the gallery), checks the file was written just now, stamps it and keeps a copy in the app's
/// storage. [front] opens the selfie camera (used for check-in/check-out proof). Returns null when cancelled or refused.
Future<CapturedPhoto?> takeStampedPhoto(
  BuildContext context,
  WidgetRef ref,
  LocalVisit visit, {
  bool front = false,
  String? title,
}) async {
  try {
    final picked = await ImagePicker().pickImage(
      source: ImageSource.camera,
      preferredCameraDevice: front ? CameraDevice.front : CameraDevice.rear,
      maxWidth: AppConfig.photoMaxEdge,
      maxHeight: AppConfig.photoMaxEdge,
      imageQuality: AppConfig.photoQuality,
    );
    if (picked == null) return null;
    final age = DateTime.now().difference(await File(picked.path).lastModified());
    if (age > const Duration(minutes: 3) || age < const Duration(minutes: -3)) {
      if (context.mounted) toast(context, 'That photo was not taken just now, so it was not accepted. Take a new photo with the camera.', error: true);
      return null;
    }
    // Stamp first (date/time, GPS, outlet, agent), then keep a copy in the app's own storage.
    final takenAt = DateTime.now();
    final gps = await ref.read(locationServiceProvider).lastKnown();
    final outlet = (await ref.read(repoProvider).assignment(visit.assignmentId))?.locationName ?? 'Outlet';
    await stampPhoto(picked.path, stampLines(takenAt: takenAt, gps: gps, outlet: outlet, agent: ref.read(sessionProvider).userName, title: title));
    return CapturedPhoto(await _persist(picked.path, ext: '.jpg'), gps);
  } catch (e) {
    if (context.mounted) toast(context, 'Could not use the camera: $e', error: true);
    return null;
  }
}

/// Takes one evidence photo with the camera. There is deliberately NO gallery option: evidence has to be captured live at the
/// outlet, so an agent cannot attach an old or downloaded picture. Two checks back this up:
///  * the file the camera returns must have been written in the last few minutes (a stale file is refused), and
///  * the upload is labelled `source=camera`; the server refuses anything else, and also flags a photo that was already
///    used on another visit (same image fingerprint).
Future<bool> captureEvidence(
  BuildContext context,
  WidgetRef ref,
  LocalVisit visit, {
  String? questionId,
}) async {
  final shot = await takeStampedPhoto(context, ref, visit);
  if (shot == null) return false;
  await ref.read(repoProvider).addPhoto(visit.localId, shot.file, questionId: questionId, gps: shot.gps);
  if (context.mounted) toast(context, 'Photo saved with its time and place. It will upload when you are online.');
  return true;
}

/// A finger-drawn signature, saved as a PNG "signature" evidence item.
Future<bool> captureSignature(BuildContext context, WidgetRef ref, LocalVisit visit, {String? questionId}) async {
  final bytes = await showModalBottomSheet<Uint8List>(
    context: context,
    isScrollControlled: true,
    builder: (c) => const _SignatureSheet(),
  );
  if (bytes == null) return false;
  final dir = await getApplicationDocumentsDirectory();
  final folder = Directory(p.join(dir.path, 'evidence'));
  if (!await folder.exists()) await folder.create(recursive: true);
  final file = await File(p.join(folder.path, '${newId()}.png')).writeAsBytes(bytes);
  await ref.read(repoProvider).addPhoto(visit.localId, file, questionId: questionId, kind: 'signature');
  if (context.mounted) toast(context, 'Signature saved.');
  return true;
}

class _SignatureSheet extends StatefulWidget {
  const _SignatureSheet();

  @override
  State<_SignatureSheet> createState() => _SignatureSheetState();
}

class _SignatureSheetState extends State<_SignatureSheet> {
  final List<List<Offset>> _strokes = [];
  final _key = GlobalKey();

  Future<void> _done() async {
    if (_strokes.isEmpty) return;
    final box = _key.currentContext!.findRenderObject() as RenderBox;
    final size = box.size;
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);
    canvas.drawRect(Offset.zero & size, Paint()..color = Colors.white);
    _paintStrokes(canvas, _strokes);
    final img = await recorder.endRecording().toImage(size.width.round(), size.height.round());
    final data = await img.toByteData(format: ui.ImageByteFormat.png);
    if (data != null && mounted) Navigator.pop(context, data.buffer.asUint8List());
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('Sign below', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
        const SizedBox(height: 10),
        Container(
          key: _key,
          height: 220,
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: const Color(0xFFCBD1E0))),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: GestureDetector(
              onPanStart: (d) => setState(() => _strokes.add([d.localPosition])),
              onPanUpdate: (d) => setState(() => _strokes.last.add(d.localPosition)),
              child: CustomPaint(painter: _SignaturePainter(_strokes), size: Size.infinite),
            ),
          ),
        ),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: OutlinedButton(onPressed: () => setState(_strokes.clear), child: const Text('Clear'))),
          const SizedBox(width: 10),
          Expanded(child: FilledButton(onPressed: _strokes.isEmpty ? null : _done, child: const Text('Save'))),
        ]),
      ]),
    );
  }
}

void _paintStrokes(Canvas canvas, List<List<Offset>> strokes) {
  final paint = Paint()
    ..color = Brand.ink
    ..strokeWidth = 3
    ..strokeCap = StrokeCap.round
    ..style = PaintingStyle.stroke;
  for (final s in strokes) {
    if (s.length == 1) {
      canvas.drawCircle(s.first, 1.5, paint..style = PaintingStyle.fill);
      paint.style = PaintingStyle.stroke;
      continue;
    }
    final path = Path()..moveTo(s.first.dx, s.first.dy);
    for (final o in s.skip(1)) {
      path.lineTo(o.dx, o.dy);
    }
    canvas.drawPath(path, paint);
  }
}

class _SignaturePainter extends CustomPainter {
  _SignaturePainter(this.strokes);
  final List<List<Offset>> strokes;

  @override
  void paint(Canvas canvas, Size size) => _paintStrokes(canvas, strokes);

  @override
  bool shouldRepaint(covariant _SignaturePainter old) => true;
}

/// Thumbnail grid of a visit's photos with delete (while the visit is editable).
class PhotoGrid extends StatelessWidget {
  const PhotoGrid({super.key, required this.photos, required this.canDelete, required this.onDelete});
  final List<Map<String, Object?>> photos;
  final bool canDelete;
  final void Function(String id) onDelete;

  @override
  Widget build(BuildContext context) {
    return GridView.count(
      crossAxisCount: 3,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 8,
      crossAxisSpacing: 8,
      children: [
        for (final ph in photos)
          Stack(fit: StackFit.expand, children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: _thumb(ph),
            ),
            Positioned(
              left: 4,
              bottom: 4,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(color: Colors.black54, borderRadius: BorderRadius.circular(8)),
                child: Text('${_kindLabel(ph['kind'])}${(ph['uploaded'] as int? ?? 0) == 1 ? 'Uploaded' : 'Waiting'}', style: const TextStyle(color: Colors.white, fontSize: 10.5, fontWeight: FontWeight.w700)),
              ),
            ),
            if (canDelete && !_isProof(ph['kind']))
              Positioned(
                right: 2,
                top: 2,
                child: InkWell(
                  onTap: () => onDelete(ph['id'] as String),
                  child: const CircleAvatar(radius: 12, backgroundColor: Colors.black54, child: Icon(Icons.close, size: 14, color: Colors.white)),
                ),
              ),
          ]),
      ],
    );
  }

  static bool _isProof(Object? kind) => kind == 'checkin' || kind == 'checkout';
  static String _kindLabel(Object? kind) => kind == 'checkin' ? 'Check-in - ' : (kind == 'checkout' ? 'Check-out - ' : '');

  Widget _thumb(Map<String, Object?> ph) {
    final path = ph['path'] as String?;
    if (path != null && File(path).existsSync()) {
      return Image.file(File(path), fit: BoxFit.cover, cacheWidth: 360);
    }
    // Uploaded photos are removed from the phone to save space; the server holds them.
    return Container(
      color: Brand.saffronSoft,
      child: Icon((ph['kind'] == 'signature') ? Icons.draw_outlined : Icons.check_circle_outline, color: Brand.muted),
    );
  }
}
