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

/// Takes (or picks) one photo for a visit. [liveOnly] forces the camera - used when the form requires live capture.
Future<bool> captureEvidence(
  BuildContext context,
  WidgetRef ref,
  LocalVisit visit, {
  String? questionId,
  bool liveOnly = true,
}) async {
  var source = ImageSource.camera;
  if (!liveOnly) {
    final chosen = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: const Icon(Icons.photo_camera_outlined), title: const Text('Take a photo'), onTap: () => Navigator.pop(c, ImageSource.camera)),
          ListTile(leading: const Icon(Icons.photo_library_outlined), title: const Text('Choose from gallery'), onTap: () => Navigator.pop(c, ImageSource.gallery)),
        ]),
      ),
    );
    if (chosen == null) return false;
    source = chosen;
  }
  try {
    final picked = await ImagePicker().pickImage(
      source: source,
      maxWidth: AppConfig.photoMaxEdge,
      maxHeight: AppConfig.photoMaxEdge,
      imageQuality: AppConfig.photoQuality,
    );
    if (picked == null) return false;
    final file = await _persist(picked.path, ext: _extOf(picked.path));
    final gps = await ref.read(locationServiceProvider).lastKnown();
    await ref.read(repoProvider).addPhoto(visit.localId, file, questionId: questionId, gps: gps);
    if (context.mounted) toast(context, 'Photo saved. It will upload when you are online.');
    return true;
  } catch (e) {
    if (context.mounted) toast(context, 'Could not capture the photo: $e', error: true);
    return false;
  }
}

String _extOf(String path) {
  final e = p.extension(path).toLowerCase();
  return const ['.jpg', '.jpeg', '.png', '.webp'].contains(e) ? e : '.jpg';
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
                child: Text((ph['uploaded'] as int? ?? 0) == 1 ? 'Uploaded' : 'Waiting', style: const TextStyle(color: Colors.white, fontSize: 10.5, fontWeight: FontWeight.w700)),
              ),
            ),
            if (canDelete)
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
