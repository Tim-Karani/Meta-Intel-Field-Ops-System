import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';
import 'photo_capture.dart';
import 'visit_scope.dart';

/// All photos for the visit (general evidence plus those attached to form questions).
class PhotosPage extends ConsumerWidget {
  const PhotosPage({super.key, required this.visitId});
  final String visitId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return VisitScope(
      visitId: visitId,
      title: 'Photos & evidence',
      fab: (c, r, v, e) => FloatingActionButton.extended(
        backgroundColor: Brand.saffron,
        foregroundColor: Brand.ink,
        icon: const Icon(Icons.photo_camera),
        label: const Text('Take photo'),
        onPressed: () async {
          final form = await r.read(repoProvider).form(v.formId);
          if (!c.mounted) return;
          await captureEvidence(c, r, v, liveOnly: form?.requiresLiveCapture ?? true);
        },
      ),
      builder: (c, r, v, editable) {
        final photos = r.watch(photosProvider(v.localId)).valueOrNull ?? const <Map<String, Object?>>[];
        if (photos.isEmpty) {
          return const EmptyState(icon: Icons.photo_camera_outlined, title: 'No photos yet', message: 'Take clear photos of the display, shelf and any proof the campaign requires.');
        }
        final waiting = photos.where((p) => (p['uploaded'] as int? ?? 0) == 0).length;
        return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
          Text(waiting == 0 ? 'All photos are uploaded.' : '$waiting photo${waiting == 1 ? '' : 's'} waiting to upload. They stay on this phone until the server confirms them.',
              style: const TextStyle(color: Brand.muted)),
          const SizedBox(height: 12),
          PhotoGrid(
            photos: photos,
            canDelete: editable,
            onDelete: (id) async {
              final yes = await confirm(c, title: 'Delete this photo?', message: 'It will be removed from the visit.', yes: 'Delete', danger: true);
              if (yes) await r.read(repoProvider).deletePhoto(id);
            },
          ),
        ]);
      },
    );
  }
}
