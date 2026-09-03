<?php
/**
 * includes/i18n.php — minimal, extensible translation layer.
 *
 * ROOT-CAUSE FIX: language.php already let visitors pick a language and
 * (after the Root Cause #1 fix in includes/config.php) can now actually save
 * it — but NOTHING on any other page ever read that choice back. The
 * "Translation Preview" table on language.php itself was the *only* place
 * the phrase dictionary was ever used. Every other page always rendered
 * hardcoded English strings regardless of the selected language, which is
 * exactly why "changing language has no effect" anywhere except that one
 * preview table.
 *
 * This file adds a real tms_t($key, $fallback) lookup used across
 * includes/header.php and includes/footer.php (the highest-visibility,
 * every-page chrome) so switching languages now visibly changes the site.
 *
 * SCOPE NOTE: fully translating every string on every one of the 60+ pages
 * (booking flow, admin panel, all AI tools, etc.) into all 8 listed
 * languages is a large, ongoing content-translation effort, not something
 * that can be responsibly hand-written in one pass without native-speaker
 * review. This ships real, working English + Bengali dictionaries (the two
 * most relevant for a Bangladesh-based audience) for the common site-wide
 * chrome, structured so more keys/languages can be added incrementally to
 * $TMS_TRANSLATIONS below without touching any page code. Any key missing
 * for the active language automatically falls back to English, so nothing
 * ever renders blank or broken while translations are still being filled in.
 */

$TMS_TRANSLATIONS = [
    'en' => [
        'nav_home'=>'Home','nav_profile'=>'My Profile','nav_bookings'=>'My Bookings','nav_wishlist'=>'Wishlist',
        'nav_support'=>'Support','nav_signin'=>'Sign In','nav_signup'=>'Sign Up','nav_logout'=>'Logout',
        'nav_welcome'=>'Welcome','nav_packages'=>'Tour Packages','nav_destinations'=>'Destinations','nav_explore'=>'Explore',
        'nav_about'=>'About','nav_contact'=>'Contact','nav_blog'=>'Blog','nav_compare'=>'Compare','nav_features'=>'Features',
        'search_placeholder'=>'Search destinations, packages...',
        'footer_quick_links'=>'Quick Links','footer_support'=>'Support','footer_features'=>'Features','footer_rights'=>'All rights reserved.',
        'btn_book_now'=>'Book Now','btn_view_details'=>'View Details','btn_add_wishlist'=>'Add to Wishlist',
        'btn_remove_wishlist'=>'Remove from Wishlist','btn_search'=>'Search','btn_reset'=>'Reset','btn_submit'=>'Submit',
        'btn_cancel'=>'Cancel','btn_save'=>'Save Changes','btn_send_message'=>'Send Message','btn_login'=>'Login',
        'btn_register'=>'Register','btn_apply'=>'Apply','btn_compare'=>'Compare','btn_read_more'=>'Read More',
        'btn_back_home'=>'Back to Home','btn_print'=>'Print','btn_edit'=>'Edit','btn_delete'=>'Delete','btn_confirm'=>'Confirm',
        'lbl_price'=>'Price','lbl_duration'=>'Duration','lbl_location'=>'Location','lbl_rating'=>'Rating',
        'lbl_per_person'=>'per person','lbl_full_name'=>'Full Name','lbl_email'=>'Email','lbl_phone'=>'Phone',
        'lbl_subject'=>'Subject','lbl_message'=>'Message','lbl_password'=>'Password','lbl_departure_date'=>'Departure Date',
        'lbl_return_date'=>'Return Date','lbl_travelers'=>'Travelers','lbl_status'=>'Status','lbl_booking_id'=>'Booking ID',
        'heading_popular_destinations'=>'Popular Destinations','heading_featured_packages'=>'Featured Packages',
        'heading_reviews'=>'Reviews','heading_book_package'=>'Book This Package','heading_related_packages'=>'Related Packages','heading_gallery'=>'Gallery',
        'heading_facilities'=>'Facilities','heading_weather'=>'Weather','heading_nearby_places'=>'Nearby Places',
        'heading_my_bookings'=>'My Bookings','heading_my_wishlist'=>'My Wishlist','heading_my_profile'=>'My Profile',
        'heading_booking_history'=>'Booking History','msg_no_packages_found'=>'No packages found',
        'msg_no_results'=>'No results found.','msg_success'=>'Success!','msg_error'=>'Something went wrong. Please try again.',
        'msg_page_not_found'=>'Page not found','msg_loading'=>'Loading...','msg_package_not_found'=>'Package not found',
    ],
    'bn' => [
        'nav_home'=>'হোম','nav_profile'=>'আমার প্রোফাইল','nav_bookings'=>'আমার বুকিং','nav_wishlist'=>'পছন্দের তালিকা',
        'nav_support'=>'সহায়তা','nav_signin'=>'সাইন ইন','nav_signup'=>'সাইন আপ','nav_logout'=>'লগআউট',
        'nav_welcome'=>'স্বাগতম','nav_packages'=>'ট্যুর প্যাকেজ','nav_destinations'=>'গন্তব্যসমূহ','nav_explore'=>'এক্সপ্লোর',
        'nav_about'=>'আমাদের সম্পর্কে','nav_contact'=>'যোগাযোগ','nav_blog'=>'ব্লগ','nav_compare'=>'তুলনা করুন','nav_features'=>'সুবিধাসমূহ',
        'search_placeholder'=>'গন্তব্য, প্যাকেজ খুঁজুন...',
        'footer_quick_links'=>'দ্রুত লিংক','footer_support'=>'সহায়তা','footer_features'=>'সুবিধাসমূহ','footer_rights'=>'সর্বস্বত্ব সংরক্ষিত।',
        'btn_book_now'=>'বুক করুন','btn_view_details'=>'বিস্তারিত দেখুন','btn_add_wishlist'=>'পছন্দের তালিকায় যুক্ত করুন',
        'btn_remove_wishlist'=>'পছন্দের তালিকা থেকে বাদ দিন','btn_search'=>'অনুসন্ধান করুন','btn_reset'=>'রিসেট','btn_submit'=>'জমা দিন',
        'btn_cancel'=>'বাতিল','btn_save'=>'পরিবর্তন সংরক্ষণ করুন','btn_send_message'=>'বার্তা পাঠান','btn_login'=>'লগইন',
        'btn_register'=>'নিবন্ধন করুন','btn_apply'=>'প্রয়োগ করুন','btn_compare'=>'তুলনা করুন','btn_read_more'=>'আরও পড়ুন',
        'btn_back_home'=>'হোমে ফিরে যান','btn_print'=>'প্রিন্ট করুন','btn_edit'=>'সম্পাদনা করুন','btn_delete'=>'মুছুন','btn_confirm'=>'নিশ্চিত করুন',
        'lbl_price'=>'মূল্য','lbl_duration'=>'সময়কাল','lbl_location'=>'স্থান','lbl_rating'=>'রেটিং',
        'lbl_per_person'=>'প্রতি ব্যক্তি','lbl_full_name'=>'পূর্ণ নাম','lbl_email'=>'ইমেইল','lbl_phone'=>'ফোন',
        'lbl_subject'=>'বিষয়','lbl_message'=>'বার্তা','lbl_password'=>'পাসওয়ার্ড','lbl_departure_date'=>'যাত্রার তারিখ',
        'lbl_return_date'=>'ফেরার তারিখ','lbl_travelers'=>'পর্যটক সংখ্যা','lbl_status'=>'অবস্থা','lbl_booking_id'=>'বুকিং আইডি',
        'heading_popular_destinations'=>'জনপ্রিয় গন্তব্য','heading_featured_packages'=>'বৈশিষ্ট্যযুক্ত প্যাকেজ',
        'heading_reviews'=>'রিভিউ','heading_book_package'=>'এই প্যাকেজ বুক করুন','heading_related_packages'=>'সম্পর্কিত প্যাকেজ','heading_gallery'=>'গ্যালারি',
        'heading_facilities'=>'সুবিধাসমূহ','heading_weather'=>'আবহাওয়া','heading_nearby_places'=>'কাছাকাছি স্থান',
        'heading_my_bookings'=>'আমার বুকিং','heading_my_wishlist'=>'আমার পছন্দের তালিকা','heading_my_profile'=>'আমার প্রোফাইল',
        'heading_booking_history'=>'বুকিং ইতিহাস','msg_no_packages_found'=>'কোনো প্যাকেজ পাওয়া যায়নি',
        'msg_no_results'=>'কোনো ফলাফল পাওয়া যায়নি।','msg_success'=>'সফল হয়েছে!','msg_error'=>'কিছু ভুল হয়েছে। আবার চেষ্টা করুন।',
        'msg_page_not_found'=>'পৃষ্ঠা পাওয়া যায়নি','msg_loading'=>'লোড হচ্ছে...','msg_package_not_found'=>'প্যাকেজ পাওয়া যায়নি',
    ],
    'hi' => [
        'nav_home'=>'होम','nav_profile'=>'मेरी प्रोफ़ाइल','nav_bookings'=>'मेरी बुकिंग','nav_wishlist'=>'विशलिस्ट',
        'nav_support'=>'सहायता','nav_signin'=>'साइन इन','nav_signup'=>'साइन अप','nav_logout'=>'लॉगआउट',
        'nav_welcome'=>'स्वागत है','nav_packages'=>'टूर पैकेज','nav_destinations'=>'गंतव्य','nav_explore'=>'एक्सप्लोर',
        'nav_about'=>'हमारे बारे में','nav_contact'=>'संपर्क करें','nav_blog'=>'ब्लॉग','nav_compare'=>'तुलना करें','nav_features'=>'सुविधाएं',
        'search_placeholder'=>'गंतव्य, पैकेज खोजें...',
        'footer_quick_links'=>'त्वरित लिंक','footer_support'=>'सहायता','footer_features'=>'सुविधाएं','footer_rights'=>'सर्वाधिकार सुरक्षित।',
        'btn_book_now'=>'अभी बुक करें','btn_view_details'=>'विवरण देखें','btn_add_wishlist'=>'विशलिस्ट में जोड़ें',
        'btn_remove_wishlist'=>'विशलिस्ट से हटाएं','btn_search'=>'खोजें','btn_reset'=>'रीसेट','btn_submit'=>'सबमिट करें',
        'btn_cancel'=>'रद्द करें','btn_save'=>'बदलाव सहेजें','btn_send_message'=>'संदेश भेजें','btn_login'=>'लॉगिन',
        'btn_register'=>'रजिस्टर करें','btn_apply'=>'लागू करें','btn_compare'=>'तुलना करें','btn_read_more'=>'और पढ़ें',
        'btn_back_home'=>'होम पर वापस जाएं','btn_print'=>'प्रिंट करें','btn_edit'=>'संपादित करें','btn_delete'=>'हटाएं','btn_confirm'=>'पुष्टि करें',
        'lbl_price'=>'कीमत','lbl_duration'=>'अवधि','lbl_location'=>'स्थान','lbl_rating'=>'रेटिंग',
        'lbl_per_person'=>'प्रति व्यक्ति','lbl_full_name'=>'पूरा नाम','lbl_email'=>'ईमेल','lbl_phone'=>'फ़ोन',
        'lbl_subject'=>'विषय','lbl_message'=>'संदेश','lbl_password'=>'पासवर्ड','lbl_departure_date'=>'प्रस्थान तिथि',
        'lbl_return_date'=>'वापसी तिथि','lbl_travelers'=>'यात्री','lbl_status'=>'स्थिति','lbl_booking_id'=>'बुकिंग आईडी',
        'heading_popular_destinations'=>'लोकप्रिय गंतव्य','heading_featured_packages'=>'विशेष पैकेज',
        'heading_reviews'=>'रिव्यू','heading_book_package'=>'यह पैकेज बुक करें','heading_related_packages'=>'संबंधित पैकेज','heading_gallery'=>'गैलरी',
        'heading_facilities'=>'सुविधाएं','heading_weather'=>'मौसम','heading_nearby_places'=>'आस-पास के स्थान',
        'heading_my_bookings'=>'मेरी बुकिंग','heading_my_wishlist'=>'मेरी विशलिस्ट','heading_my_profile'=>'मेरी प्रोफ़ाइल',
        'heading_booking_history'=>'बुकिंग इतिहास','msg_no_packages_found'=>'कोई पैकेज नहीं मिला',
        'msg_no_results'=>'कोई परिणाम नहीं मिला।','msg_success'=>'सफल!','msg_error'=>'कुछ गलत हो गया। कृपया पुनः प्रयास करें।',
        'msg_page_not_found'=>'पेज नहीं मिला','msg_loading'=>'लोड हो रहा है...','msg_package_not_found'=>'पैकेज नहीं मिला',
    ],
    'ar' => [
        'nav_home'=>'الرئيسية','nav_profile'=>'ملفي الشخصي','nav_bookings'=>'حجوزاتي','nav_wishlist'=>'المفضلة',
        'nav_support'=>'الدعم','nav_signin'=>'تسجيل الدخول','nav_signup'=>'إنشاء حساب','nav_logout'=>'تسجيل الخروج',
        'nav_welcome'=>'مرحباً','nav_packages'=>'باقات السفر','nav_destinations'=>'الوجهات','nav_explore'=>'استكشف',
        'nav_about'=>'من نحن','nav_contact'=>'اتصل بنا','nav_blog'=>'المدونة','nav_compare'=>'مقارنة','nav_features'=>'الميزات',
        'search_placeholder'=>'ابحث عن وجهات، باقات...',
        'footer_quick_links'=>'روابط سريعة','footer_support'=>'الدعم','footer_features'=>'الميزات','footer_rights'=>'جميع الحقوق محفوظة.',
        'btn_book_now'=>'احجز الآن','btn_view_details'=>'عرض التفاصيل','btn_add_wishlist'=>'أضف إلى المفضلة',
        'btn_remove_wishlist'=>'إزالة من المفضلة','btn_search'=>'بحث','btn_reset'=>'إعادة تعيين','btn_submit'=>'إرسال',
        'btn_cancel'=>'إلغاء','btn_save'=>'حفظ التغييرات','btn_send_message'=>'إرسال الرسالة','btn_login'=>'دخول',
        'btn_register'=>'تسجيل','btn_apply'=>'تطبيق','btn_compare'=>'مقارنة','btn_read_more'=>'قراءة المزيد',
        'btn_back_home'=>'العودة للرئيسية','btn_print'=>'طباعة','btn_edit'=>'تعديل','btn_delete'=>'حذف','btn_confirm'=>'تأكيد',
        'lbl_price'=>'السعر','lbl_duration'=>'المدة','lbl_location'=>'الموقع','lbl_rating'=>'التقييم',
        'lbl_per_person'=>'للفرد','lbl_full_name'=>'الاسم الكامل','lbl_email'=>'البريد الإلكتروني','lbl_phone'=>'الهاتف',
        'lbl_subject'=>'الموضوع','lbl_message'=>'الرسالة','lbl_password'=>'كلمة المرور','lbl_departure_date'=>'تاريخ المغادرة',
        'lbl_return_date'=>'تاريخ العودة','lbl_travelers'=>'المسافرون','lbl_status'=>'الحالة','lbl_booking_id'=>'رقم الحجز',
        'heading_popular_destinations'=>'الوجهات الشائعة','heading_featured_packages'=>'باقات مميزة',
        'heading_reviews'=>'التقييمات','heading_book_package'=>'احجز هذه الباقة','heading_related_packages'=>'باقات ذات صلة','heading_gallery'=>'معرض الصور',
        'heading_facilities'=>'المرافق','heading_weather'=>'الطقس','heading_nearby_places'=>'أماكن قريبة',
        'heading_my_bookings'=>'حجوزاتي','heading_my_wishlist'=>'قائمة مفضلتي','heading_my_profile'=>'ملفي الشخصي',
        'heading_booking_history'=>'سجل الحجوزات','msg_no_packages_found'=>'لم يتم العثور على باقات',
        'msg_no_results'=>'لا توجد نتائج.','msg_success'=>'تم بنجاح!','msg_error'=>'حدث خطأ ما. يرجى المحاولة مرة أخرى.',
        'msg_page_not_found'=>'الصفحة غير موجودة','msg_loading'=>'جارٍ التحميل...','msg_package_not_found'=>'الباقة غير موجودة',
    ],
    'fr' => [
        'nav_home'=>'Accueil','nav_profile'=>'Mon Profil','nav_bookings'=>'Mes Réservations','nav_wishlist'=>'Liste de souhaits',
        'nav_support'=>'Assistance','nav_signin'=>'Connexion','nav_signup'=>'Inscription','nav_logout'=>'Déconnexion',
        'nav_welcome'=>'Bienvenue','nav_packages'=>'Forfaits','nav_destinations'=>'Destinations','nav_explore'=>'Explorer',
        'nav_about'=>'À propos','nav_contact'=>'Contact','nav_blog'=>'Blog','nav_compare'=>'Comparer','nav_features'=>'Fonctionnalités',
        'search_placeholder'=>'Rechercher des destinations, forfaits...',
        'footer_quick_links'=>'Liens rapides','footer_support'=>'Assistance','footer_features'=>'Fonctionnalités','footer_rights'=>'Tous droits réservés.',
        'btn_book_now'=>'Réserver','btn_view_details'=>'Voir les détails','btn_add_wishlist'=>'Ajouter aux favoris',
        'btn_remove_wishlist'=>'Retirer des favoris','btn_search'=>'Rechercher','btn_reset'=>'Réinitialiser','btn_submit'=>'Envoyer',
        'btn_cancel'=>'Annuler','btn_save'=>'Enregistrer','btn_send_message'=>'Envoyer le message','btn_login'=>'Connexion',
        'btn_register'=>"S'inscrire",'btn_apply'=>'Appliquer','btn_compare'=>'Comparer','btn_read_more'=>'Lire la suite',
        'btn_back_home'=>"Retour à l'accueil",'btn_print'=>'Imprimer','btn_edit'=>'Modifier','btn_delete'=>'Supprimer','btn_confirm'=>'Confirmer',
        'lbl_price'=>'Prix','lbl_duration'=>'Durée','lbl_location'=>'Lieu','lbl_rating'=>'Note',
        'lbl_per_person'=>'par personne','lbl_full_name'=>'Nom complet','lbl_email'=>'E-mail','lbl_phone'=>'Téléphone',
        'lbl_subject'=>'Sujet','lbl_message'=>'Message','lbl_password'=>'Mot de passe','lbl_departure_date'=>'Date de départ',
        'lbl_return_date'=>'Date de retour','lbl_travelers'=>'Voyageurs','lbl_status'=>'Statut','lbl_booking_id'=>'ID de réservation',
        'heading_popular_destinations'=>'Destinations populaires','heading_featured_packages'=>'Forfaits en vedette',
        'heading_reviews'=>'Avis','heading_book_package'=>'Réserver ce forfait','heading_related_packages'=>'Forfaits similaires','heading_gallery'=>'Galerie',
        'heading_facilities'=>'Équipements','heading_weather'=>'Météo','heading_nearby_places'=>'Lieux à proximité',
        'heading_my_bookings'=>'Mes réservations','heading_my_wishlist'=>'Ma liste de souhaits','heading_my_profile'=>'Mon profil',
        'heading_booking_history'=>'Historique des réservations','msg_no_packages_found'=>'Aucun forfait trouvé',
        'msg_no_results'=>'Aucun résultat trouvé.','msg_success'=>'Succès !','msg_error'=>"Une erreur s'est produite. Veuillez réessayer.",
        'msg_page_not_found'=>'Page non trouvée','msg_loading'=>'Chargement...','msg_package_not_found'=>'Forfait non trouvé',
    ],
    'zh' => [
        'nav_home'=>'首页','nav_profile'=>'我的资料','nav_bookings'=>'我的预订','nav_wishlist'=>'心愿单',
        'nav_support'=>'客服支持','nav_signin'=>'登录','nav_signup'=>'注册','nav_logout'=>'退出登录',
        'nav_welcome'=>'欢迎','nav_packages'=>'旅游套餐','nav_destinations'=>'目的地','nav_explore'=>'探索',
        'nav_about'=>'关于我们','nav_contact'=>'联系我们','nav_blog'=>'博客','nav_compare'=>'比较','nav_features'=>'功能',
        'search_placeholder'=>'搜索目的地、套餐...',
        'footer_quick_links'=>'快速链接','footer_support'=>'客服支持','footer_features'=>'功能','footer_rights'=>'版权所有。',
        'btn_book_now'=>'立即预订','btn_view_details'=>'查看详情','btn_add_wishlist'=>'加入心愿单',
        'btn_remove_wishlist'=>'从心愿单移除','btn_search'=>'搜索','btn_reset'=>'重置','btn_submit'=>'提交',
        'btn_cancel'=>'取消','btn_save'=>'保存更改','btn_send_message'=>'发送消息','btn_login'=>'登录',
        'btn_register'=>'注册','btn_apply'=>'应用','btn_compare'=>'比较','btn_read_more'=>'阅读更多',
        'btn_back_home'=>'返回首页','btn_print'=>'打印','btn_edit'=>'编辑','btn_delete'=>'删除','btn_confirm'=>'确认',
        'lbl_price'=>'价格','lbl_duration'=>'时长','lbl_location'=>'地点','lbl_rating'=>'评分',
        'lbl_per_person'=>'每人','lbl_full_name'=>'姓名','lbl_email'=>'电子邮箱','lbl_phone'=>'电话',
        'lbl_subject'=>'主题','lbl_message'=>'留言','lbl_password'=>'密码','lbl_departure_date'=>'出发日期',
        'lbl_return_date'=>'返程日期','lbl_travelers'=>'出行人数','lbl_status'=>'状态','lbl_booking_id'=>'预订编号',
        'heading_popular_destinations'=>'热门目的地','heading_featured_packages'=>'精选套餐',
        'heading_reviews'=>'评价','heading_book_package'=>'预订此套餐','heading_related_packages'=>'相关套餐','heading_gallery'=>'图库',
        'heading_facilities'=>'设施','heading_weather'=>'天气','heading_nearby_places'=>'附近地点',
        'heading_my_bookings'=>'我的预订','heading_my_wishlist'=>'我的心愿单','heading_my_profile'=>'我的资料',
        'heading_booking_history'=>'预订历史','msg_no_packages_found'=>'未找到套餐',
        'msg_no_results'=>'未找到结果。','msg_success'=>'成功！','msg_error'=>'出现错误，请重试。',
        'msg_page_not_found'=>'页面未找到','msg_loading'=>'加载中...','msg_package_not_found'=>'未找到套餐',
    ],
    'es' => [
        'nav_home'=>'Inicio','nav_profile'=>'Mi Perfil','nav_bookings'=>'Mis Reservas','nav_wishlist'=>'Lista de deseos',
        'nav_support'=>'Soporte','nav_signin'=>'Iniciar sesión','nav_signup'=>'Registrarse','nav_logout'=>'Cerrar sesión',
        'nav_welcome'=>'Bienvenido','nav_packages'=>'Paquetes turísticos','nav_destinations'=>'Destinos','nav_explore'=>'Explorar',
        'nav_about'=>'Acerca de','nav_contact'=>'Contacto','nav_blog'=>'Blog','nav_compare'=>'Comparar','nav_features'=>'Características',
        'search_placeholder'=>'Buscar destinos, paquetes...',
        'footer_quick_links'=>'Enlaces rápidos','footer_support'=>'Soporte','footer_features'=>'Características','footer_rights'=>'Todos los derechos reservados.',
        'btn_book_now'=>'Reservar ahora','btn_view_details'=>'Ver detalles','btn_add_wishlist'=>'Añadir a la lista de deseos',
        'btn_remove_wishlist'=>'Quitar de la lista de deseos','btn_search'=>'Buscar','btn_reset'=>'Restablecer','btn_submit'=>'Enviar',
        'btn_cancel'=>'Cancelar','btn_save'=>'Guardar cambios','btn_send_message'=>'Enviar mensaje','btn_login'=>'Iniciar sesión',
        'btn_register'=>'Registrarse','btn_apply'=>'Aplicar','btn_compare'=>'Comparar','btn_read_more'=>'Leer más',
        'btn_back_home'=>'Volver al inicio','btn_print'=>'Imprimir','btn_edit'=>'Editar','btn_delete'=>'Eliminar','btn_confirm'=>'Confirmar',
        'lbl_price'=>'Precio','lbl_duration'=>'Duración','lbl_location'=>'Ubicación','lbl_rating'=>'Calificación',
        'lbl_per_person'=>'por persona','lbl_full_name'=>'Nombre completo','lbl_email'=>'Correo electrónico','lbl_phone'=>'Teléfono',
        'lbl_subject'=>'Asunto','lbl_message'=>'Mensaje','lbl_password'=>'Contraseña','lbl_departure_date'=>'Fecha de salida',
        'lbl_return_date'=>'Fecha de regreso','lbl_travelers'=>'Viajeros','lbl_status'=>'Estado','lbl_booking_id'=>'ID de reserva',
        'heading_popular_destinations'=>'Destinos populares','heading_featured_packages'=>'Paquetes destacados',
        'heading_reviews'=>'Reseñas','heading_book_package'=>'Reservar este paquete','heading_related_packages'=>'Paquetes relacionados','heading_gallery'=>'Galería',
        'heading_facilities'=>'Instalaciones','heading_weather'=>'Clima','heading_nearby_places'=>'Lugares cercanos',
        'heading_my_bookings'=>'Mis reservas','heading_my_wishlist'=>'Mi lista de deseos','heading_my_profile'=>'Mi perfil',
        'heading_booking_history'=>'Historial de reservas','msg_no_packages_found'=>'No se encontraron paquetes',
        'msg_no_results'=>'No se encontraron resultados.','msg_success'=>'¡Éxito!','msg_error'=>'Algo salió mal. Inténtalo de nuevo.',
        'msg_page_not_found'=>'Página no encontrada','msg_loading'=>'Cargando...','msg_package_not_found'=>'Paquete no encontrado',
    ],
    'de' => [
        'nav_home'=>'Startseite','nav_profile'=>'Mein Profil','nav_bookings'=>'Meine Buchungen','nav_wishlist'=>'Wunschliste',
        'nav_support'=>'Support','nav_signin'=>'Anmelden','nav_signup'=>'Registrieren','nav_logout'=>'Abmelden',
        'nav_welcome'=>'Willkommen','nav_packages'=>'Reisepakete','nav_destinations'=>'Reiseziele','nav_explore'=>'Entdecken',
        'nav_about'=>'Über uns','nav_contact'=>'Kontakt','nav_blog'=>'Blog','nav_compare'=>'Vergleichen','nav_features'=>'Funktionen',
        'search_placeholder'=>'Reiseziele, Pakete suchen...',
        'footer_quick_links'=>'Schnelllinks','footer_support'=>'Support','footer_features'=>'Funktionen','footer_rights'=>'Alle Rechte vorbehalten.',
        'btn_book_now'=>'Jetzt buchen','btn_view_details'=>'Details ansehen','btn_add_wishlist'=>'Zur Wunschliste hinzufügen',
        'btn_remove_wishlist'=>'Von Wunschliste entfernen','btn_search'=>'Suchen','btn_reset'=>'Zurücksetzen','btn_submit'=>'Absenden',
        'btn_cancel'=>'Abbrechen','btn_save'=>'Änderungen speichern','btn_send_message'=>'Nachricht senden','btn_login'=>'Anmelden',
        'btn_register'=>'Registrieren','btn_apply'=>'Anwenden','btn_compare'=>'Vergleichen','btn_read_more'=>'Weiterlesen',
        'btn_back_home'=>'Zurück zur Startseite','btn_print'=>'Drucken','btn_edit'=>'Bearbeiten','btn_delete'=>'Löschen','btn_confirm'=>'Bestätigen',
        'lbl_price'=>'Preis','lbl_duration'=>'Dauer','lbl_location'=>'Ort','lbl_rating'=>'Bewertung',
        'lbl_per_person'=>'pro Person','lbl_full_name'=>'Vollständiger Name','lbl_email'=>'E-Mail','lbl_phone'=>'Telefon',
        'lbl_subject'=>'Betreff','lbl_message'=>'Nachricht','lbl_password'=>'Passwort','lbl_departure_date'=>'Abreisedatum',
        'lbl_return_date'=>'Rückreisedatum','lbl_travelers'=>'Reisende','lbl_status'=>'Status','lbl_booking_id'=>'Buchungs-ID',
        'heading_popular_destinations'=>'Beliebte Reiseziele','heading_featured_packages'=>'Empfohlene Pakete',
        'heading_reviews'=>'Bewertungen','heading_book_package'=>'Dieses Paket buchen','heading_related_packages'=>'Ähnliche Pakete','heading_gallery'=>'Galerie',
        'heading_facilities'=>'Ausstattung','heading_weather'=>'Wetter','heading_nearby_places'=>'Orte in der Nähe',
        'heading_my_bookings'=>'Meine Buchungen','heading_my_wishlist'=>'Meine Wunschliste','heading_my_profile'=>'Mein Profil',
        'heading_booking_history'=>'Buchungsverlauf','msg_no_packages_found'=>'Keine Pakete gefunden',
        'msg_no_results'=>'Keine Ergebnisse gefunden.','msg_success'=>'Erfolgreich!','msg_error'=>'Etwas ist schiefgelaufen. Bitte versuchen Sie es erneut.',
        'msg_page_not_found'=>'Seite nicht gefunden','msg_loading'=>'Wird geladen...','msg_package_not_found'=>'Paket nicht gefunden',
    ],
];

/**
 * Resolves the visitor's active language: session (set this request) ->
 * long-lived cookie (persists across logout/browser restarts, per the "never
 * reset to English automatically" requirement) -> English default.
 */
function tms_active_language(): string {
    $allowed = ['en', 'bn', 'hi', 'fr', 'ar', 'zh', 'es', 'de'];
    $lang = $_SESSION['lang'] ?? $_COOKIE['tms_lang'] ?? 'en';
    return in_array($lang, $allowed, true) ? $lang : 'en';
}

/**
 * Translates dynamic, database-driven content (package names/descriptions,
 * CMS page text, etc.) that a static UI dictionary can't cover — there's no
 * realistic way to hand-write 400+ packages worth of text in 8 languages.
 *
 * Strategy: every unique source string is translated ONCE per language via
 * the AI client already used for the site's other AI features, then cached
 * forever in tbl_translation_cache (keyed by an md5 of the text + language).
 * Every subsequent view of that same content is an instant cache read, no
 * AI call. Multiple strings are sent in a single batched AI call (one
 * request per page-load-with-cache-misses, not one per string) to keep
 * things fast. If the AI call fails for any reason (including the
 * suspicious-looking GEMINI_API_KEY flagged elsewhere in this file), this
 * safely falls back to the original English text — it can never break a
 * page or block it from rendering.
 *
 * @param array  $texts  ['key' => 'Source text', ...]
 * @param string $lang   target language code
 * @return array         ['key' => 'Translated (or original) text', ...]
 */
function tms_translate_batch(array $texts, string $lang, PDO $dbh): array {
    if ($lang === 'en' || empty($texts)) return $texts;

    $result = [];
    $misses = [];
    $hashOf = [];

    foreach ($texts as $key => $text) {
        $text = (string)$text;
        if (trim($text) === '') { $result[$key] = $text; continue; }
        $hash = md5($text);
        $hashOf[$key] = $hash;
        $misses[$key] = $text; // assume miss until proven cached below
    }
    if (!$misses) return $result;

    // Check cache for all candidates in one query.
    try {
        $hashes = array_values(array_unique($hashOf));
        if ($hashes) {
            $in = implode(',', array_fill(0, count($hashes), '?'));
            $stmt = $dbh->prepare("SELECT source_hash, translated FROM tbl_translation_cache WHERE lang = ? AND source_hash IN ($in)");
            $stmt->execute(array_merge([$lang], $hashes));
            $cacheRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($hashOf as $key => $hash) {
                if (isset($cacheRows[$hash])) {
                    $result[$key] = $cacheRows[$hash];
                    unset($misses[$key]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[TMS i18n] Cache lookup failed: ' . $e->getMessage());
    }
    if (!$misses) return $result;

    // Ask the AI to translate everything still missing, in one call.
    if (!function_exists('curl_init')) {
        error_log('[TMS i18n] curl extension not available — skipping AI translation, showing original text.');
        foreach ($misses as $key => $text) { $result[$key] = $text; }
        return $result;
    }
    // ROOT-CAUSE FIX (caught via live testing before release): AIClient is only
    // required by individual AI-feature pages (admin/ai-dashboard.php, api/ai.php,
    // etc.), never globally. Since includes/i18n.php loads on every page, calling
    // `new AIClient(...)` here without requiring the class first threw an uncaught
    // "Class AIClient not found" fatal on EVERY page as soon as a non-English
    // language was selected — exactly the kind of blank/broken page this whole
    // audit has been fixing. Loaded lazily here so English-only requests (the
    // majority) pay zero extra cost.
    require_once __DIR__ . '/ai/AIClient.php';
    try {
        $ai = new AIClient($dbh);
        $langNames = ['bn'=>'Bengali','hi'=>'Hindi','ar'=>'Arabic','fr'=>'French','zh'=>'Simplified Chinese','es'=>'Spanish','de'=>'German'];
        $langName = $langNames[$lang] ?? $lang;
        $payload = array_values($misses);
        $system = "You translate short tourism-website UI text into $langName. "
                . "You will receive a JSON array of strings. Return ONLY a JSON array of the same length, "
                . "in the same order, each element the natural $langName translation of the corresponding input. "
                . "Keep numbers, prices, and proper nouns for well-known places recognisable. No commentary, no markdown fences.";
        $res = $ai->ask($system, json_encode($payload, JSON_UNESCAPED_UNICODE));

        if ($res['success']) {
            $translated = $ai->parseJson($res['content']);
            if (is_array($translated) && count($translated) === count($payload)) {
                $ins = $dbh->prepare("INSERT INTO tbl_translation_cache (source_hash, lang, translated, source_text) VALUES (:h,:l,:t,:s)
                                      ON DUPLICATE KEY UPDATE translated = VALUES(translated), source_text = VALUES(source_text)");
                $i = 0;
                foreach ($misses as $key => $text) {
                    $t = is_string($translated[$i]) && trim($translated[$i]) !== '' ? $translated[$i] : $text;
                    $result[$key] = $t;
                    try { $ins->execute([':h' => $hashOf[$key], ':l' => $lang, ':t' => $t, ':s' => $text]); } catch (Throwable $e) {}
                    $i++;
                }
                return $result;
            }
        }
        error_log('[TMS i18n] Batch translation to ' . $lang . ' failed or malformed: ' . ($res['error'] ?? 'unexpected AI response shape'));
    } catch (Throwable $e) {
        error_log('[TMS i18n] Batch translation error: ' . $e->getMessage());
    }

    // AI unavailable/failed — fall back to the original text so the page still renders correctly.
    foreach ($misses as $key => $text) { $result[$key] = $text; }
    return $result;
}
function tms_t(string $key, string $fallback = ''): string {
    global $TMS_TRANSLATIONS;
    $lang = tms_active_language();
    return $TMS_TRANSLATIONS[$lang][$key]
        ?? $TMS_TRANSLATIONS['en'][$key]
        ?? ($fallback !== '' ? $fallback : $key);
}
