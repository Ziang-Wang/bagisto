<!-- Page Layout -->
<x-shop::layouts>
    <!-- Page Title -->
    <x-slot:title>
        @lang('shop::app.home.contact.title')
    </x-slot>

    @push('styles')
        <style>
            .um-contact { display: grid; grid-template-columns: 1fr 1fr; gap: 3.5rem; align-items: start; margin: 2rem 0 4rem; }
            @media (max-width: 992px) { .um-contact { grid-template-columns: 1fr; gap: 2.5rem; } }

            .um-contact-info h1 { font-size: 2.5rem; font-weight: 800; line-height: 1.15; color: #111827; margin: 0; }
            @media (max-width: 640px) { .um-contact-info h1 { font-size: 1.75rem; } }
            .um-contact-info .um-divider { border: 0; border-top: 1px solid #e5e7eb; margin: 1.75rem 0; }
            .um-contact-info .um-item { display: flex; align-items: flex-start; gap: 1.15rem; margin: 1.75rem 0; }
            .um-contact-info .um-icon { flex: 0 0 auto; width: 58px; height: 58px; border: 2px solid #e11b22; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #e11b22; }
            .um-contact-info .um-icon svg { width: 28px; height: 28px; }
            .um-contact-info .um-item h3 { font-size: 1.35rem; font-weight: 700; color: #111827; margin: 0 0 .3rem; }
            .um-contact-info .um-item p { color: #4b5563; margin: 0; line-height: 1.55; word-break: break-word; }
            .um-contact-info .um-item a { color: #4b5563; }

            .um-contact-form { background: #f5f5f5; border-radius: 14px; padding: 2.75rem; }
            @media (max-width: 640px) { .um-contact-form { padding: 1.5rem; } }
            .um-contact-form .um-eyebrow { color: #e11b22; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; text-decoration: underline; margin: 0 0 .35rem; }
            .um-contact-form h2 { font-size: 2.25rem; font-weight: 800; color: #111827; margin: 0 0 1.5rem; }
            @media (max-width: 640px) { .um-contact-form h2 { font-size: 1.6rem; } }
            .um-contact-form .um-submit { background: #e11b22; color: #fff; font-weight: 600; border-radius: 10px; padding: .9rem 2.5rem; border: 0; cursor: pointer; transition: background .2s; }
            .um-contact-form .um-submit:hover { background: #b91419; }
        </style>
    @endpush

    <div class="container mt-8 max-1180:px-5 max-md:mt-6 max-md:px-4">
        <div class="um-contact">
            <!-- Left: contact information -->
            <div class="um-contact-info">
                <h1>@lang('shop::app.home.contact.title')</h1>

                <hr class="um-divider">

                <div class="um-item">
                    <span class="um-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </span>
                    <div>
                        <h3>Call Us</h3>
                        <p><a href="tel:+8619195550159">+86 19195550159</a></p>
                    </div>
                </div>

                <div class="um-item">
                    <span class="um-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    </span>
                    <div>
                        <h3>Email</h3>
                        <p><a href="mailto:urmotorparts@gmail.com">urmotorparts@gmail.com</a></p>
                    </div>
                </div>

                <div class="um-item">
                    <span class="um-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    </span>
                    <div>
                        <h3>Address</h3>
                        <p>RM248 Wushan Road, Tianhe Dist, Guangzhou, 510000, China</p>
                    </div>
                </div>
            </div>

            <!-- Right: Get in Touch form -->
            <div class="um-contact-form">
                <p class="um-eyebrow">Send Us A Message</p>
                <h2>Get in Touch</h2>

                <x-shop::form :action="route('shop.home.contact_us.send_mail')">
                    <!-- Name -->
                    <x-shop::form.control-group>
                        <x-shop::form.control-group.label class="required">
                            @lang('shop::app.home.contact.name')
                        </x-shop::form.control-group.label>

                        <x-shop::form.control-group.control
                            type="text"
                            class="px-6 py-4"
                            name="name"
                            rules="required"
                            :value="old('name')"
                            :label="trans('shop::app.home.contact.name')"
                            :placeholder="trans('shop::app.home.contact.name')"
                            :aria-label="trans('shop::app.home.contact.name')"
                            aria-required="true"
                        />

                        <x-shop::form.control-group.error control-name="name" />
                    </x-shop::form.control-group>

                    <!-- Email -->
                    <x-shop::form.control-group>
                        <x-shop::form.control-group.label class="required">
                            @lang('shop::app.home.contact.email')
                        </x-shop::form.control-group.label>

                        <x-shop::form.control-group.control
                            type="email"
                            class="px-6 py-4"
                            name="email"
                            rules="required|email"
                            :value="old('email')"
                            :label="trans('shop::app.home.contact.email')"
                            :placeholder="trans('shop::app.home.contact.email')"
                            :aria-label="trans('shop::app.home.contact.email')"
                            aria-required="true"
                        />

                        <x-shop::form.control-group.error control-name="email" />
                    </x-shop::form.control-group>

                    <!-- Tel / Contact -->
                    <x-shop::form.control-group>
                        <x-shop::form.control-group.label>
                            @lang('shop::app.home.contact.phone-number')
                        </x-shop::form.control-group.label>

                        <x-shop::form.control-group.control
                            type="text"
                            class="px-6 py-4"
                            name="contact"
                            rules="phone"
                            :value="old('contact')"
                            :label="trans('shop::app.home.contact.phone-number')"
                            :placeholder="trans('shop::app.home.contact.phone-number')"
                            :aria-label="trans('shop::app.home.contact.phone-number')"
                        />

                        <x-shop::form.control-group.error control-name="contact" />
                    </x-shop::form.control-group>

                    <!-- Message -->
                    <x-shop::form.control-group>
                        <x-shop::form.control-group.label class="required">
                            @lang('shop::app.home.contact.desc')
                        </x-shop::form.control-group.label>

                        <x-shop::form.control-group.control
                            type="textarea"
                            class="px-6 py-4"
                            name="message"
                            rules="required"
                            :label="trans('shop::app.home.contact.message')"
                            :placeholder="trans('shop::app.home.contact.describe-here')"
                            :aria-label="trans('shop::app.home.contact.message')"
                            aria-required="true"
                            rows="6"
                        />

                        <x-shop::form.control-group.error control-name="message" />
                    </x-shop::form.control-group>

                    <!-- Captcha -->
                    @if (core()->getConfigData('customer.captcha.credentials.status'))
                        <x-shop::form.control-group class="mt-5">
                            {!! \Webkul\Customer\Facades\Captcha::render() !!}

                            <x-shop::form.control-group.error control-name="recaptcha_token" />
                        </x-shop::form.control-group>
                    @endif

                    <!-- Submit Button -->
                    <div class="mt-6">
                        <button class="um-submit" type="submit">
                            @lang('shop::app.home.contact.submit')
                        </button>
                    </div>
                </x-shop::form>
            </div>
        </div>
    </div>

    @push('scripts')
        {!! \Webkul\Customer\Facades\Captcha::renderJS() !!}
    @endpush
</x-shop::layouts>
