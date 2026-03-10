package J2EE.bai4.config;

import J2EE.bai4.model.Account;
import J2EE.bai4.model.Role;
import J2EE.bai4.repository.AccountRepository;
import J2EE.bai4.repository.RoleRepository;
import org.springframework.boot.CommandLineRunner;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.crypto.password.PasswordEncoder;

import java.util.List;

@Configuration
public class DataLoader {

    @Bean
    public CommandLineRunner initData(AccountRepository accountRepository,
                                      RoleRepository roleRepository,
                                      PasswordEncoder passwordEncoder) {
        return args -> {
            if (accountRepository.count() > 0) {
                return;
            }

            Role roleUser = roleRepository.findByName("ROLE_USER")
                    .orElseGet(() -> roleRepository.save(new Role("ROLE_USER")));
            Role roleAdmin = roleRepository.findByName("ROLE_ADMIN")
                    .orElseGet(() -> roleRepository.save(new Role("ROLE_ADMIN")));

            Account user = new Account();
            user.setUsername("user");
            user.setPassword(passwordEncoder.encode("123456"));
            user.setEnabled(true);
            user.setRoles(List.of(roleUser));
            accountRepository.save(user);

            Account admin = new Account();
            admin.setUsername("admin");
            admin.setPassword(passwordEncoder.encode("admin123"));
            admin.setEnabled(true);
            admin.setRoles(List.of(roleAdmin));
            accountRepository.save(admin);
        };
    }
}
