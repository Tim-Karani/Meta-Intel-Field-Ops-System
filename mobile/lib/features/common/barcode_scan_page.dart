import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// Full-screen camera that reads one barcode or QR code and returns its text (null if the person goes back).
class BarcodeScanPage extends StatefulWidget {
  const BarcodeScanPage({super.key, this.title = 'Scan barcode'});
  final String title;

  /// Opens the scanner and returns what was read.
  static Future<String?> open(BuildContext context, {String title = 'Scan barcode'}) =>
      Navigator.of(context).push<String>(MaterialPageRoute(builder: (_) => BarcodeScanPage(title: title)));

  @override
  State<BarcodeScanPage> createState() => _BarcodeScanPageState();
}

class _BarcodeScanPageState extends State<BarcodeScanPage> {
  final MobileScannerController _controller = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates);
  bool _done = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_done) return;
    for (final b in capture.barcodes) {
      final v = (b.rawValue ?? '').trim();
      if (v.isNotEmpty) {
        _done = true;
        Navigator.of(context).pop(v);
        return;
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.title)),
        body: Stack(children: [
          MobileScanner(controller: _controller, onDetect: _onDetect),
          Align(
            alignment: Alignment.bottomCenter,
            child: Container(
              width: double.infinity,
              color: Colors.black54,
              padding: const EdgeInsets.all(16),
              child: const Text('Hold the phone steady so the barcode is inside the screen.', textAlign: TextAlign.center, style: TextStyle(color: Colors.white)),
            ),
          ),
        ]),
      );
}
