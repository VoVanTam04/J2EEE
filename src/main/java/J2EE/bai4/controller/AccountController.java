package J2EE.bai4.controller;

import J2EE.bai4.model.Account;
import J2EE.bai4.model.Role;
import J2EE.bai4.repository.AccountRepository;
import J2EE.bai4.repository.RoleRepository;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

@Controller
public class AccountController {

    private final AccountRepository accountRepository;
    private final RoleRepository roleRepository;
    private final PasswordEncoder passwordEncoder;

    public AccountController(AccountRepository accountRepository, RoleRepository roleRepository, PasswordEncoder passwordEncoder) {
        this.accountRepository = accountRepository;
        this.roleRepository = roleRepository;
        this.passwordEncoder = passwordEncoder;
    }

    @GetMapping("/register")
    public String register(Model model) {
        model.addAttribute("account", new Account());
        return "account/register";
    }

    @PostMapping("/register")
    public String registerUser(Account account, RedirectAttributes redirectAttributes) {
        if (accountRepository.findByUsername(account.getUsername()).isPresent()) {
            redirectAttributes.addFlashAttribute("error", "Tên đăng nhập đã tồn tại, vui lòng chọn tên khác!");
            return "redirect:/register";
        }

        account.setPassword(passwordEncoder.encode(account.getPassword()));
        account.setEnabled(true);
        Role userRole = roleRepository.findByName("ROLE_USER").orElseGet(() -> roleRepository.save(new Role("ROLE_USER")));
        account.getRoles().add(userRole);
        
        accountRepository.save(account);

        redirectAttributes.addFlashAttribute("success", "Đăng ký thành công! Bạn có thể đăng nhập ngay bây giờ.");
        return "redirect:/login";
    }
}
