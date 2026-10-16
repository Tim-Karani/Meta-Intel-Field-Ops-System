import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api_exception.dart';
import '../../state/providers.dart';

class LoginPage extends ConsumerStatefulWidget {
  const LoginPage({super.key});

  @override
  ConsumerState<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends ConsumerState<LoginPage> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _login;
  final _password = TextEditingController();
  late final TextEditingController _server;
  bool _busy = false;
  bool _hide = true;
  bool _showServer = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final s = ref.read(sessionProvider);
    _login = TextEditingController(text: s.user?['email']?.toString() ?? '');
    _server = TextEditingController(text: s.baseUrl);
  }

  @override
  void dispose() {
    _login.dispose();
    _password.dispose();
    _server.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final session = ref.read(sessionProvider);
    try {
      await session.setBaseUrl(_server.text);
      await session.login(_login.text, _password.text);
      // The router redirects to /home when the session notifies.
    } on ApiException catch (e) {
      setState(() => _error = e.kind == ApiErrorKind.network ? 'Cannot reach the server at ${session.baseUrl}. Check the address and your connection.' : e.display);
    } on StateError catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'Sign-in failed: $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final expired = ref.watch(sessionProvider).expired;
    return Scaffold(
      backgroundColor: Brand.ink,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(color: Brand.saffron, borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.place_outlined, color: Brand.ink),
                  ),
                  const SizedBox(width: 12),
                  const Text('Field Operations', style: TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
                ]),
                const SizedBox(height: 28),
                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
                  child: Form(
                    key: _form,
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Text(expired ? 'Sign in again' : 'Sign in', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
                      const SizedBox(height: 4),
                      Text(
                        expired ? 'Your session expired. Work saved on this phone is kept and will sync after you sign in.' : 'Use the account your supervisor created for you.',
                        style: const TextStyle(color: Brand.muted),
                      ),
                      const SizedBox(height: 18),
                      TextFormField(
                        controller: _login,
                        keyboardType: TextInputType.emailAddress,
                        autocorrect: false,
                        textInputAction: TextInputAction.next,
                        decoration: const InputDecoration(labelText: 'Email or username', prefixIcon: Icon(Icons.person_outline)),
                        validator: (v) => (v == null || v.trim().isEmpty) ? 'Enter your email or username.' : null,
                      ),
                      const SizedBox(height: 12),
                      TextFormField(
                        controller: _password,
                        obscureText: _hide,
                        textInputAction: TextInputAction.done,
                        onFieldSubmitted: (_) => _busy ? null : _submit(),
                        decoration: InputDecoration(
                          labelText: 'Password',
                          prefixIcon: const Icon(Icons.lock_outline),
                          suffixIcon: IconButton(icon: Icon(_hide ? Icons.visibility_outlined : Icons.visibility_off_outlined), onPressed: () => setState(() => _hide = !_hide)),
                        ),
                        validator: (v) => (v == null || v.isEmpty) ? 'Enter your password.' : null,
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 12),
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(color: Brand.bad.withAlpha(25), borderRadius: BorderRadius.circular(10)),
                          child: Text(_error!, style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w600)),
                        ),
                      ],
                      const SizedBox(height: 16),
                      FilledButton(
                        onPressed: _busy ? null : _submit,
                        child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4)) : const Text('Sign in'),
                      ),
                      const SizedBox(height: 4),
                      TextButton(
                        onPressed: () => setState(() => _showServer = !_showServer),
                        child: Text(_showServer ? 'Hide server settings' : 'Server settings'),
                      ),
                      if (_showServer)
                        TextFormField(
                          controller: _server,
                          keyboardType: TextInputType.url,
                          autocorrect: false,
                          decoration: const InputDecoration(
                            labelText: 'Server address',
                            helperText: 'Emulator: http://10.0.2.2:8080/api/v1 - real phone: http://<your-PC-IP>:8080/api/v1',
                            helperMaxLines: 3,
                          ),
                        ),
                    ]),
                  ),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
