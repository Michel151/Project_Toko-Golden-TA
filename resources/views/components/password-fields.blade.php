                    <div class="mb-3">
                        <label for="userPassword" class="form-label fw-semibold">
                            {{ $passwordLabel ?? 'Password' }} {{ $passwordOptional ? '(kosongkan jika tidak diganti)' : '' }}
                            @if(!$passwordOptional) <span class="text-danger">*</span> @endif
                        </label>
                        <div class="input-group has-validation">
                        <input id="userPassword" type="password" name="password" autocomplete="new-password" aria-describedby="passwordRequirements"
                               class="form-control @error('password') is-invalid @enderror"
                               {{ !$passwordOptional ? 'required' : '' }}
                               minlength="8" placeholder="Masukkan password yang kuat">
                        <button type="button" class="btn btn-outline-secondary" data-password-toggle="userPassword" data-password-label="password" aria-controls="userPassword" aria-pressed="false" aria-label="Tampilkan password" title="Tampilkan password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <ul id="passwordRequirements" class="list-unstyled small mt-2 mb-0">
                            @foreach(['length'=>'Minimal 8 karakter', 'uppercase'=>'Mengandung huruf besar (A–Z)', 'lowercase'=>'Mengandung huruf kecil (a–z)', 'number'=>'Mengandung angka (0–9)', 'symbol'=>'Mengandung simbol, misalnya !, @, atau #'] as $rule=>$description)
                            <li class="text-muted d-flex align-items-start gap-2 mb-1" data-password-rule="{{ $rule }}"><i class="bi bi-circle" aria-hidden="true"></i><span><span data-rule-status class="visually-hidden">Belum terpenuhi: </span>{{ $description }}</span></li>
                            @endforeach
                        </ul>
                        <div id="passwordRequirementsStatus" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
                    </div>

                    
                    <div class="mb-3">
                        <label for="userPasswordConfirmation" class="form-label fw-semibold">Konfirmasi Password</label>
                        <div class="input-group">
                        <input id="userPasswordConfirmation" type="password" name="password_confirmation" class="form-control" autocomplete="new-password" aria-describedby="passwordMatchStatus"
                               placeholder="Ulangi password" {{ !$passwordOptional ? 'required' : '' }}>
                        <button type="button" class="btn btn-outline-secondary" data-password-toggle="userPasswordConfirmation" data-password-label="konfirmasi password" aria-controls="userPasswordConfirmation" aria-pressed="false" aria-label="Tampilkan konfirmasi password" title="Tampilkan konfirmasi password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                        <div id="passwordMatchStatus" class="form-text" role="status" aria-live="polite" aria-atomic="true">Ulangi password untuk memastikan keduanya sama.</div>
                    </div>


